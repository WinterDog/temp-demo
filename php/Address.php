<?php
	/**
	 * Адрес с дополнительной информацией.
	 * Структура используется для передачи туда-сюда адреса при расчётах выездов вместе с доп. данными.
	 * В перспективе желательно перейти на использование данного класса при передаче адресов, чтобы задействовать кэширование по максимуму.
	 */
	class Address
	{
		/** @var string */
		private $address = null;
		/** @var string */
		private $address_clean = null;
		/** @var float[] Координаты точки, от которой считался выезд, если считался (широта, долгота). */
		private $from_coords = null;
		/** @var float[] Координаты адреса (широта, долгота). */
		private $coords = null;
		/** @var string Ключ из таблицы кэша. */
		private $cache_id = null;
		/** @var string Строковый уникальный ключ для таблицы кэша. Состоит из ФИАС улицы + разделитель "_" + номер дома. */
		private $fias_cache_id = null;
		/** @var string ФИАС улицы или ближайшего более высокого уровня (нас. пункта, города и т. п.). */
		private $fias_cache_clean_fias_id = null;
		/** @var string Часть адреса после улицы и ниже уровнем (номер дома, корпуса и т. д.). */
		private $fias_cache_clean_house_number = null;

		/** @var City|null - город данного адреса */
		private $city = null;
		/** @var int Расстояние при выезде до адреса в один конец. */
		private $distance = null;
		/** @var string Cтатус валидации адреса через DaData*/
		private $validate_status = null;
		//--------------------------------------------------
		const VALID_ADDRESS_STATUS = 'valid';
		const INVALID_ADDRESS_STATUS = 'invalid';
		public static $validate_statuses = [
			self::VALID_ADDRESS_STATUS,
			self::INVALID_ADDRESS_STATUS
		];
		/**
		 * Создание объекта-адреса из строки-адреса.
		 *
		 * @param string $address Адрес строкой.
		 * @param City|null $city Город адреса. Может пригодиться по двум причинам:
		 * - чтобы не делать запросов к ДаДате по зарубежным городам;
		 * - чтобы уточнить адрес названием страны.
		 * Если город известен, лучше его передать. Если нет, объект сам попытается его определить через геокодирование, но это скольская дорожка!
		 *
		 * @return Address|null Метод может вернуть null, если объект не был создан. Это возможно, если адрес пустой.
		 * @throws Exception
		 */
		public static function create($address, City $city = null)
		{
			/** @var string[] $cache_address_variants Варианты написания адреса, которые будут выниматься из кэша. */
			$cache_address_variants = [];
			$address = $address ? trim($address) : null;

			// Реальный город. Используем только реальный город для контактов.
			$real_city = $city ? $city->realCity() : null;

			// Если адрес не пришёл, мы всё же можем создать адресный объект по полному названию населённого пункта.
			if (!$address)
			{
				// Если и города нет, создавать нечего.
				if (!isset($real_city))
					return null;

				// Формируем адрес из полного названия нас. пункта.
				$address = $real_city->fullCityTitleOrdered() ?: $real_city->fullCityTitle() ?: $real_city->title();
				// Добавляем также вариант без региона - именно он сработает для большинства филиалов.
				$cache_address_variants[] = $real_city->fullCityTitleOrdered(false);
			}
			// Основной адрес подставляем в начало.
			array_unshift($cache_address_variants, $address);

			/** @var mixed[] $cache Запись из кэша. */
			$cache = null;
			$addressCacheDP = new AddressCacheDataProvider();
			// Пытаемся вынуть запись из кэша по адресу. Это может не сработать, если Сфинкс не успел проиндексировать новые адреса.
			// На такой случай внизу есть запасной вариант.
			foreach ($cache_address_variants as $address_variant)
			{
				if ($cache = $addressCacheDP->getCacheByAddress($address_variant))
					break;
			}

			if ($cache)
			{
				// Нашли кэш, пишем данные из него.
				return self::createFromCache($cache, $city);
			}

			$geo_coder = new Geocoder();

			if ($city && $city->country()->id() != GeoDataProvider::COUNTRY_RUSSIA)
			{
				// пробуем сохранить в кэш иностранные города во избежение повторных запросов к гео-кодеру
				// для этого, получаем координаты через яндекс
				$coords_array = $geo_coder->getAddressData($address, Geocoder::GEO_SERVICE_YANDEX);
				// если пришли координаты - запоминаем их и прокидываем в конструктор для сохранения в кэше
				$coords = $coords_array['coords'] ?? null;
				// получаем объект с координатами и сохраняем в кэш
				$object = new self($address, $address, $city, null, $coords);
				$object->createCache();

				// разумеется, сохраняем с нулём
				return $object->setAddressValidStatus(self::VALID_ADDRESS_STATUS);
			}

			// Геокодируем адрес, получаем ФИАС и город.
			$address_data = $geo_coder->getAddressData($address, Geocoder::GEO_SERVICE_DADATA);

			// Адрес не декодировался - создаём минимальный объект с адресом и городом и выходим.
			if (!$address_data)
			{
				$object = new self($address, $address, $city);
				return $object->setAddressValidStatus(self::INVALID_ADDRESS_STATUS);
			}

			// Если город по адресу не был определён или он был определён, но явно некорректно (филиал не совпадает с филиалом присланного города),
			// пишем это в лог и выдаём объект с исходным адресом и городом (может быть пустым).
			if (!$address_data['city'] || $city && $city->parentCityId() != $address_data['city']->parentCityId())
			{
				// Вынимаем коллстек.
				$e = new Exception();
				$trace = $e->getTraceAsString();

				SaveGeoQueryAction::log(
					SaveGeoQueryAction::TYPE_ADDRESS,
					$address . ' | ' . $address_data['address_clean'],
					SaveGeoQueryAction::RESULT_ERROR,
					'[' . $trace . ']'
				);

				$object = new self($address, $address, $city);
				return $object->setAddressValidStatus(self::INVALID_ADDRESS_STATUS);
			}

			// Пытаемся вынуть запись из кэша по ФИАС-ключу. Вынимаем в обход Сфинкса, сразу из таблицы.
			$cache = $addressCacheDP->getCacheByFiasCacheId($address_data['fias_cache_id']);

			if ($cache)
			{
				// Нашли кэш, пишем данные из него.
				return self::createFromCache($cache, $city);
			}

			// Если город не был передан, берём определённый по адресу.
			// Если город по адресу был определён и совпадает с переданным или является его городом доставки
			// берём определённый по адресу.
			// На данном этапе город по адресу в любом случае будет определён и при этом корректно (либо не будет города на входе, либо филиалы будут совпадать).
			// Поэтому другие сценарии отрабатывать нет смысла.
			/** @var City|null $address_city */
			$address_city = $address_data['city'];

			// Адрес декодировался, но кэш не найден - пишем, что можно, в поля.
			$object = new self($address, $address_data['address_clean'], $address_city, $address_data['fias_cache_id'], $address_data['coords']);

			// Сохраняем запись в кэш. Пока без координат и расстояния.
			// Сохраняем только в том случае, если авто-определённый город совпадает с переданным (либо ничего не было передано).
			// todo Вероятно, нужно всё-таки кэшировать все адреса на данном этапе, потому что точное совпадение городов - штука редкая.
			if ($city && $address_city->realCityId() == $city->id())
				$object->setAddressValidStatus(self::VALID_ADDRESS_STATUS)->createCache();
			else
				$object->setAddressValidStatus(self::INVALID_ADDRESS_STATUS);

			return $object;
		}
		//--------------------------------------------------

		/**
		 * Создание объекта по коду ФИАС.
		 *
		 * @param string $fias_id Код ФИАС.
		 *
		 * @return Address|null Метод может вернуть null, если объект не был создан. Это возможно, если ФИАС пустой или не распознан.
		 *
		 * @throws Exception
		 */
		public static function createFromFiasId($fias_id)
		{
			$addressCacheDP = new AddressCacheDataProvider();
			$cache = $addressCacheDP->getCacheByFiasCacheId($fias_id);

			if ($cache)
				return self::createFromCache($cache);

			$geocoder = new Geocoder();
			$address_data = $geocoder->getAddressDataByFiasId($fias_id);

			if (!$address_data)
				return null;

			// Адрес декодировался, но кэш не найден - пишем, что можно, в поля.
			$object = new self($address_data['address_clean'], $address_data['address_clean'], $address_data['city'], $address_data['fias_cache_id'], $address_data['coords']);
			// Сохраняем запись в кэш. Пока без координат и расстояния.
			$object->createCache();

			return $object;
		}
		//--------------------------------------------------

		/**
		 * Создание объекта-адреса из данных адресного кэша.
		 *
		 * @param mixed[] $cache Запись из адресного кэша.
		 *
		 * @param City|null $city Город, присланный снаружи при создании адресного объекта.
		 * Нужен, чтобы можно было установить для адреса виртуальный город.
		 *
		 * @return Address|null
		 * @throws Exception
		 */
		protected static function createFromCache($cache, City $city = null)
		{
			if (!$cache)
				return null;

			// Город, сохранённый в кэш-таблице.
			$city_repository = new CityRepository();
			$cache_city = $city_repository->findById($cache['geo_id']);

			// Снаружи нам могли прислать виртуальный город.
			// Если он пришёл и является копией города из кэша, устанавливаем его. Иначе берём город из кэша.
			$city = ($city && $city->realCityId() == $cache_city->id()) ? $city : $cache_city;

			$object = new self(
				$cache['address'],
				$cache['address'],
				$city,
				$cache['fias_cache_id'],
				$cache['to_lat'] !== null ? array((float) $cache['to_lat'], (float) $cache['to_lon']) : null,
				$cache['from_lat'] !== null ? array((float) $cache['from_lat'], (float) $cache['from_lon']) : null,
				$cache['distance'],
				$cache['cache_id']
			);
			return $object->setAddressValidStatus(self::VALID_ADDRESS_STATUS);
		}
		//--------------------------------------------------

		/**
		 * Закрытый конструктор.
		 *
		 * @param string $address
		 * @param string $address_clean
		 * @param City|null $city
		 * @param string $fias_cache_id
		 * @param float[] $coords
		 * @param float[] $from_coords
		 * @param int $distance
		 * @param int $cache_id
		 */
		protected function __construct($address, $address_clean, City $city = null, $fias_cache_id = null, $coords = null, $from_coords = null, $distance = null, $cache_id = null)
		{
			$this->address = $address;
			$this->address_clean = $address_clean;
			$this->fias_cache_id = $fias_cache_id;
			$this->coords = $coords;
			$this->from_coords = $from_coords;
			$this->city = $city;
			$this->distance = $distance;
			$this->cache_id = $cache_id;

			if ($fias_cache_id)
			{
				$fias_cache_id_array = explode('_', $fias_cache_id);
				$this->fias_cache_clean_fias_id = $fias_cache_id_array[0];

				if (sizeof($fias_cache_id_array) > 1)
					$this->fias_cache_clean_house_number = $fias_cache_id_array[1];
			}
		}
		//--------------------------------------------------

		/**
		 * @return string Метод возвращает либо адрес, очищенный ДаДатой (в её формате), либо исходный адрес, если ДаДата его не распознала.
		 */
		public function cleanAddress()
		{
			return $this->address_clean;
		}
		//--------------------------------------------------

		/**
		 * @return int Числовой уникальный идентификатор записи в кэше.
		 */
		public function cacheId()
		{
			return $this->cache_id;
		}
		//--------------------------------------------------

		/**
		 * @return string Уникальный ФИАС-код записи. Будет пустым для нероссийских адресов.
		 */
		public function fiasCacheId()
		{
			return $this->fias_cache_id;
		}
		//--------------------------------------------------

		/**
		 * @return string ФИАС улицы из кэша.
		 */
		public function fiasId()
		{
			return $this->fias_cache_clean_fias_id;
		}
		//--------------------------------------------------

		/**
		 * @return string Номер дома из кэша.
		 */
		public function houseNumber()
		{
			return $this->fias_cache_clean_house_number;
		}
		//--------------------------------------------------

		/**
		 * @return City|null - город адреса
		 */
		public function city()
		{
			return $this->city;
		}
		//--------------------------------------------------

		/**
		 * @return float[]|null Координаты адреса. Либо берутся закэшированные, либо делается запрос геокодинга.
		 */
		public function coords()
		{
			// Если в кэше координат нет, геокодируем адрес в координаты.
			if (!$this->coords)
			{
				$address = $this->cleanAddress();
				// Если есть город, то в геокодер отправляем не только адрес, но и страну, чтобы избежать коллизий ("г Иваново, ул Карла Маркса, д 3").
				if ($this->city())
					$address = $this->city()->country()->title() . ', ' . $address;

				$geo_coder = new Geocoder();
				$this->coords = $geo_coder->geocodeAddress($address);

				if ($this->coords)
				{
					$this->updateCache([
						'to' => $this->coords,
					]);
				}
			}

			return $this->coords;
		}
		//--------------------------------------------------

		/**
		 * @return float[]|null Координаты точки, от которой считался выезд.
		 */
		public function fromCoords()
		{
			return $this->from_coords;
		}
		//--------------------------------------------------

		/**
		 * @return int Расстояние выезда за пределы города в один конец.
		 */
		public function distance()
		{
			return $this->distance;
		}
		//--------------------------------------------------

		/**
		 * Сохранить расстояние выезда за пределы города в один конец.
		 *
		 * @param int $distance
		 */
		public function setDistance($distance)
		{
			$this->distance = $distance;
		}
		//--------------------------------------------------

		/**
		 * Сохранить адрес в кэш.
		 *
		 * @param int $distance Расстояние в один конец. 0 означает отсутствие выезда.
		 * @param float[] $point_from Координаты точки, от которой считался выезд. Может и не быть, если выезд не считался.
		 * @param float[] $point_to Координаты самого адреса.
		 *
		 * @return bool Результат операции (true - всё хорошо).
		 */
		/*public function saveToCache($distance = null, $point_from = array(), $point_to = array())
		{
			if (!$this->fiasCacheId() || !$this->city())
				return false;

			$addressCacheDataProvider = new AddressCacheDataProvider();
			return $addressCacheDataProvider->addCache(array(
				'parent_geo_id' => $this->city()->mainCity() ? $this->city()->mainCity()->id() : $this->city()->id(),
				'geo_id' => $this->city()->id(),
				'fias_cache_id' => $this->fiasCacheId(),
				'address' => $this->cleanAddress(),
				'from_lat' => $point_from ? $point_from[0] : null,
				'from_lon' => $point_from ? $point_from[1] : null,
				'to_lat' => $point_to ? $point_to[0] : null,
				'to_lon' => $point_to ? $point_to[1] : null,
				'distance' => $distance,
			));
		}*/
		//--------------------------------------------------

		/**
		 * Сохранить адрес в кэш.
		 *
		 * @return bool Результат операции (true - всё хорошо).
		 */
		protected function createCache()
		{
			// if (!$this->fiasCacheId() || !$this->city())
			if (!$this->city())
				return false;

			$addressCacheDataProvider = new AddressCacheDataProvider();
			$cache_id = $addressCacheDataProvider->addCache(array(
				'address' => $this->cleanAddress(),
				'fias_cache_id' => $this->fiasCacheId(),
				'geo_id' => $this->city()->realCityId(),
				'parent_geo_id' => $this->city()->realCity()->parentCityId(),
				'to_lat' => $this->coords ? $this->coords[0] : null,
				'to_lon' => $this->coords ? $this->coords[1] : null,
			));

			if ($cache_id)
				$this->cache_id = $cache_id;

			return $cache_id ? true : false;
		}
		//--------------------------------------------------

		/**
		 * Сохранить адрес в кэш.
		 *
		 * @param mixed[] $params Параметры:
		 * - float[] 'point_from' Координаты точки, от которой считался выезд. Может и не быть, если выезд не считался.
		 * - float[] 'point_to' Координаты самого адреса.
		 * - int 'distance' Расстояние в один конец. 0 означает отсутствие выезда.
		 *
		 * @return bool Результат операции (true - всё хорошо).
		 */
		public function updateCache(array $params)
		{
			if (!$this->cacheId())
				return false;

			$update_params = [];

			if (isset($params['from']) && $params['from'])
			{
				$update_params += [
					'from_lat' => $params['from'][0],
					'from_lon' => $params['from'][1],
				];
			}
			if (isset($params['to']) && $params['to'])
			{
				$update_params += [
					'to_lat' => $params['to'][0],
					'to_lon' => $params['to'][1],
				];
			}
			if (isset($params['distance']))
			{
				$update_params += [
					'distance' => $params['distance'],
				];
			}

			$addressCacheDataProvider = new AddressCacheDataProvider();
			return $addressCacheDataProvider->editCache($this->cacheId(), $update_params);
		}
		//--------------------------------------------------

		private function setAddressValidStatus($status)
		{
			if (in_array($status, self::$validate_statuses))
				$this->validate_status = $status;

			return $this;
		}

		public function getAddressValidStatus()
		{
			return $this->validate_status;
		}
		//--------------------------------------------------
	}
