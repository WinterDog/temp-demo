<?php
	/**
	 * Расчёт расстояний, въездов и выездов.
	 * Основные сценарии использования:
	 * - getGeoServices(), в который передаётся населённый пункт и адрес для получения массива услуг по выезду за пределы города или въезду во внутригородские зоны.
	 * Используется для сборки и паллетной перевозки.
	 * - getGeoServicesProvince(), который работает так же, только для накладных типа "Город и область".
	 * Соответственно, сюда передаём населённый пункт / адрес отправления и назначения.
	 */
	class Geocoder
	{
		const GEO_SERVICE_DADATA = 'dadata';
		const GEO_SERVICE_YANDEX = 'yandex';
		const GEO_SERVICE_GOOGLE = 'google';
		//--------------------------------------------------------------------------------

		// API-ключи к гео-сервисам.
		const API_KEY_DADATA = '';
		// Ключ к Маршрутизатору.
		const API_KEY_YANDEX_ROUTE = '';
		// Ключ к JavaScript API и HTTP-геокодеру.
		const API_KEY_YANDEX_GEOCODE = '';
		const API_KEY_GOOGLE = '';
		//--------------------------------------------------------------------------------

		// Сколько раз пробовать перестроить маршрут по новой, если вернулась ошибка.
		// Яндекс раньше иногда выкидывал ошибку при построении маршрута, но повторные запросы могли выдать корректный маршрут.
		// Поэтому мы делаем несколько попыток построить путь.
		// Сейчас это вроде бы не актуально, поскольку в Яндексе что-то исправили, и с подобным поведением мы больше не сталкивались.
		const ROUTE_ERROR_RETRIES = 3;

		// Запрос успешно выполнен (например, маршрут построен).
		const RESULT_CODE_SUCCESS = 0;
		// Не было ответа от сервиса или ответ некорректный.
		const RESULT_CODE_NO_RESPONSE = 1;
		// Пришёл ответ без результата (например, сервису не удалось построить маршрут).
		const RESULT_CODE_NO_RESULT = 2;
		// Вернулась ошибка.
		const RESULT_CODE_ERROR = 3;
		//--------------------------------------------------------------------------------

		/**
		 * @var string Текущий гео-сервис для геокодинга. Можно выбирать любой из 3-х.
		 * ВАЖНО: У DaData очень слабое покрытие гео-координатами:
		 * https://dadata.userecho.com/knowledge-bases/4/articles/1067-geokoordinatyi
		 * Желательно использовать Яндекс или Google.
		 */
		private static $geocode_service = self::GEO_SERVICE_YANDEX;
		/**
		 * @var string Текущий гео-сервис для построения маршрутов. Можно выбирать Яндекс или Google.
		 * HTTP API Яндекса и Яндекс.Карты строят маршруты через разные внутренние маршрутизаторы. В результате маршруты могут отличаться на 5-7 %.
		 */
		private static $route_service = self::GEO_SERVICE_YANDEX;
		//--------------------------------------------------------------------------------

		/**
		 * @var null|string Последний сервис, который использовался для построения маршрута.
		 * Если один гео-сервис отказывает, происходит временное переключение на альтернативный.
		 * Эта переменная нужна, чтобы корректно записать в коммент к услуге, через какой гео-сервис был в итоге рассчитан километраж.
		 */
		private $last_route_service = null;
		//--------------------------------------------------------------------------------

		/**
		 * Получить массив гео-услуг для заданного города и координат.
		 *
		 * @param City|null $city Город выезда.
		 * @param float[]|null|string $point Координаты или адрес выезда.
		 *
		 * @return mixed[][] Массив услуг.
		 * @throws Exception
		 */
		public function getGeoServices(City $city = null, $point = null)
		{
			if (!$city)
			{
				return [];
			}

			// Работаем только с реальными городами.
			$city = $city->realCity();

			// Если город - филиал и адрес не задан, никаких просчётов не делаем.
			if ($city->parentCity()->id() == $city->id() && !$point)
				return [];

			$oAddress = Address::create($point, $city);

			$services = $this->getGeoServicesLeaving($city, $oAddress);
			$services = array_merge($services, $this->getGeoServicesEntering($city, $oAddress));

			// Выдаём индексированный массив по service_id.
			$services = array_combine(array_column($services, 'service_id'), $services);
			return $services;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Рассчитать услуги по выезду за пределы города и въезду в зоны для города и области.
		 *
		 * @param City|null $from_city Город выезда (отправления).
		 * @param float[]|null|string $from_point Координаты или адрес выезда (отправления).
		 * @param City|null $to_city Город выезда (назначения).
		 * @param float[]|null|string $to_point Координаты или адрес выезда (назначения).
		 *
		 * @return mixed[][] Массив услуг.
		 * @throws Exception
		 */
		public function getGeoServicesProvince(City $from_city = null, $from_point = null, City $to_city = null, $to_point = null)
		{
			if (!$from_city || !$to_city)
				return [];

			// Работаем только с реальными городами.
			$from_city = $from_city->realCity();
			$to_city = $to_city->realCity();

			// Родительские города не совпадают - по идее, это ошибка.
			if ($from_city->parentCity()->id() != $to_city->parentCity()->id())
				return [];

			// Услуги по выезду за пределы города.
			$services_leaving = [];
			// Услуги по въезду в зоны внутри города (например, в ТТК или Садовое).
			$services_entering = [];

			// Считаем услуги для точки отправления.
			$from_point = Address::create($from_point, $from_city);

			$services_leaving = array_merge(
				$services_leaving,
				$this->getGeoServicesLeaving($from_city, $from_point));

			$services_entering = array_merge(
				$services_entering,
				$this->getGeoServicesEntering($from_city, $from_point));

			// Считаем услуги для точки назначения.
			$to_point = Address::create($to_point, $to_city);

			$services_leaving = array_merge(
				$services_leaving,
				$this->getGeoServicesLeaving($to_city, $to_point));

			$services_entering = array_merge(
				$services_entering,
				$this->getGeoServicesEntering($to_city, $to_point));

			// Слитые вместе услуги по въезду в зоны.
			$merged_services = [];

			// Склеенная услуга по выезду за пределы города.
			// Для ГиО выезд может быть и для пункта отправления, и для пункта назначения.
			// Но в итоговом массиве услуг по выезду должна быть одна запись с общим километражом.
			// Перебираем все услуги по выезду (собственно, их будет от 0 до 2) и сливаем километражи вместе.
			$merged_service_leaving = null;
			// Перебираем услуги по выезду.
			foreach ($services_leaving as $service)
			{
				// Нашли первую - заполняем данные по услуге (service_id и т. п.).
				if (!$merged_service_leaving)
				{
					$merged_service_leaving = $service;
					$merged_service_leaving['note'] = 'Километраж общий';
				}
				// Для всех остальных просто добавляем километраж.
				else
					$merged_service_leaving['amount'] += $service['amount'];
			}

			// Если услуга была сформирована, добавляем её в массив услуг по выезду.
			if ($merged_service_leaving)
				$merged_services[$merged_service_leaving['service_id']] = $merged_service_leaving;

			// Въезд в зону должен считаться один раз.
			// Например, если и пункт отправления, и пункт назначения находятся в пределах ТТК, то это будет один факт въезда, а не два.
			// Поэтому оставляем уникальные услуги по въезду, устраняя дубли.
			foreach ($services_entering as $service)
			{
				// Нашли первую - заполняем данные по услуге (service_id и т. п.).
				if (!isset($merged_services[$service['service_id']]))
					$merged_services[$service['service_id']] = $service;
			}

			// Возвращаем склеенный массив услуг.
			return $merged_services;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить массив услуг по выезду за пределы города.
		 *
		 * @param City $city Город выполнения услуги.
		 * @param Address|null $oAddress Точка забора / доставки (координаты).
		 *
		 * @return mixed[][]
		 */
		protected function getGeoServicesLeaving(City $city, Address $oAddress = null)
		{
			// Услуга по выезду. Для Москвы своя.
			$service_id = ($city->parentCity()->id() == GeoDataProvider::CITY_MOSCOW)
				? ServiceDataProvider::MKAD_LEAVING_EXTRA_SERVICE
				: ServiceDataProvider::CITY_LEAVING_EXTRA_SERVICE;

			$serviceDP = new ServiceDataProvider();
			$service = $serviceDP->getService($service_id);

			// Услуга по выезду за пределы города. По умолчанию предполагаем, что расстояние определить не удалось.
			$service = [
				'service_id' => $service_id,
				'from_city_id' => $city->parentCity()->id(),
				'to_city_id' => $city->parentCity()->id(),
				'title' => $service['title'],
				'amount' => 0,
			];

			$geoDP = new GeoDataProvider();

			// Если город доставки и не в Москве, то вынимаем расстояние из БД.
			// Для регионов сотрудники могут вручную забить в БД километраж для конкретных городов. Его ищем в приоритетном порядке.
			if ($city->id() != $city->parentCity()->id() && $city->parentCity()->id() != GeoDataProvider::CITY_MOSCOW)
			{
				$map_row = $geoDP->getParentCityDistance($city->parentCity()->id(), $city->id());
				if ($map_row)
				{
					$service = array_merge($service, [
						'amount' => $map_row['distance'] * 2,
						'note' => 'Километраж из базы данных',
					]);
					return [ $service ];
				}
			}

			/**
			 * Метод получает объект-адрес и заготовку услуги по выезду и дописывает километраж и комментарий.
			 *
			 * @param Address $oAddress
			 * @param mixed[] $service
			 *
			 * @return mixed[]
			 */
			$leaving_service_func = function ($oAddress, $service)
			{
				return array_merge($service, [
					'amount' => $oAddress->distance() * 2,
					'note' => "Километраж из кэша (" . $oAddress->cacheId() . ") | " . json_encode(array($oAddress->fromCoords(), $oAddress->coords())),
				]);
			};

			if ($oAddress)
			{
				// Если адрес содержит рассчитанный километраж, выходим.
				if ($oAddress->distance() !== null)
				{
					// Километраж положительный - возвращаем выезд.
					if ($oAddress->distance())
						return [ $leaving_service_func($oAddress, $service) ];
					else
						return [];
				}
			}

			// Если адрес не пришёл или координаты не удалось определить...
			if (!$oAddress || !$oAddress->coords())
			{
				// Берём за адрес сам населённый пункт.
				$oAddress = Address::create(null, $city);

				// Если адрес содержит рассчитанный километраж, выходим.
				if ($oAddress && $oAddress->distance() !== null)
				{
					// Километраж положительный - возвращаем выезд.
					if ($oAddress->distance())
						return [ $leaving_service_func($oAddress, $service) ];
					else
						return [];
				}
			}

			/** @var float[] $point_to Координаты точки назначения. */
			$point_to = $oAddress ? $oAddress->coords() : null;

			// Если нет координат точки назначения, дальше считать выезд бессмысленно.
			if (!$point_to)
			{
				// Если город - не филиал, мы точно знаем, что выезд есть. Возвращаем его с нулевым километражом.
				if ($city->id() != $city->parentCity()->id())
				{
					$service['note'] = 'Координаты адреса не определены';
					return [ $service ];
				}
				else
					return [];
			}

			/** @var float[] $point_from Координаты точки отправления. */
			$point_from = null;
			// Расстояние напрямик от границы города до точки назначения.
			// Это был крайний вариант на случай, если маршрут не удалось построить. Сейчас не используется.
			//$direct_distance = null;

			// Вынимаем границы города.
			$border_data = $geoDP->getCityOuterBorders($city->parentCity()->id());
			$border_points = $border_data['border'];

			// Если есть границы города...
			if ($border_points)
			{
				// Точка назначения внутри границ - выходим.
				// Разворачиваем координаты, потому что границы у нас хранятся в формате Яндекса - lon-lat.
				if (self::pointInPolygon(array($point_to[1], $point_to[0]), $border_points))
				{
					// Сохраняем инфу в кэш.
					if ($oAddress)
					{
						$oAddress->updateCache([
							'from' => $point_from,
							'to' => $point_to,
							'distance' => 0,
						]);
					}
					return [];
				}

				// Если есть границы города, вычисляем координаты ближайшей точки полигона и расстояние напрямик.
				// На всякий случай следует учесть, что запрос может быть и не выполнен, например, если границы забиты некорректно.
				$nearest_point = $this->nearestPointOfPolygon(array($point_to[1], $point_to[0]), $border_points);

				if ($nearest_point)
				{
					$point_from = $nearest_point['point'];
					$point_from = array($point_from[1], $point_from[0]);
					//$direct_distance = $nearest_point['distance'];
				}
			}

			// Границ нет или они некорректны.
			if (!$point_from)
			{
				// Если не город доставки, то считать выезд бессмысленно.
				// В это условие мы можем попасть одним из двух способов:
				// 1. Адрес в филиале, но для него нет границ. Тогда никаких выездов не нужно. Скорее всего, границы скоро появятся.
				// 2. Адрес в городе альтернативы. Тогда мы не можем рассчитать выезд, но и сохранять в кэш ничего не нужно. Поэтому сохранение закомменчено.
				if ($city->id() == $city->parentCity()->id())
				{
					// Сохраняем инфу в кэш.
					//if ($oAddress)
					//	$oAddress->saveToCache(0, $point_from, $point_to);

					return [];
				}

				// Получаем координаты по названию города.
				$oFromAddress = Address::create(null, $city->parentCity());
				$point_from = $oFromAddress->coords();
			}

			// Если мы здесь, выезд точно должен быть, потому что либо точка за границами, либо выбран город доставки.
			// Если координаты точки отправления так и не удалось определить, выходим.
			if (!$point_from)
			{
				$service['note'] = 'Некорректные границы города или координаты города не определены';
				return array($service);
			}

			$distance = $this->getDistance($point_from, $point_to, $result_code);

			// Если не удалось проложить маршрут, берём расстояние напрямик, если оно есть.
			//if (!$distance)
			//	$distance = $direct_distance;

			// Приводим к числам, чтобы не было кавычек.
			$point_from[0] = (float) $point_from[0];
			$point_from[1] = (float) $point_from[1];
			$point_to[0] = (float) $point_to[0];
			$point_to[1] = (float) $point_to[1];

			// Нет расстояния, хоть ты тресни.
			if (!$distance)
			{
				$service['note'] = 'Не удалось рассчитать километраж';

				// Возвращаем на форму координаты точек и инфу о том, что маршрут построен не был.
				// Форма сама попробует рассчитать километраж через Яндекс.Карты.
				// Добавлено: на самом деле, нет, это уже не работает.
				if ($result_code == self::RESULT_CODE_NO_RESULT)
				{
					$service['point_from'] = $point_from;
					$service['point_to'] = $point_to;
					$service['no_route'] = 1;
				}

				return [ $service ];
			}

			// Последний использованный гео-сервис.
			$geo_service_title = $this->last_route_service == self::GEO_SERVICE_YANDEX ? 'Яндекс' : 'Google';

			// Дописываем к услуге коммент и километраж.
			$service = array_merge($service, [
				'amount' => $distance * 2,
				'note' => "Километраж из просчёта через $geo_service_title | " . json_encode([ $point_from, $point_to ]),
			]);

			// Сохраняем адрес в кэш-таблицу только тогда, когда пришедший в параметре город и город, определённый по адресу, совпадают.
			if ($oAddress && $oAddress->city() && $city && $oAddress->city()->realCity()->id() == $city->id())
			{
				//$oAddress->saveToCache($distance, $point_from, $point_to);
				$oAddress->updateCache([
					'from' => $point_from,
					'to' => $point_to,
					'distance' => $distance,
				]);
			}

			return [ $service ];
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить массив услуг по въезду в зоны.
		 *
		 * @param City $city Город выполнения услуги.
		 * @param Address|null $oAddress Точка забора / доставки (координаты).
		 *
		 * @return mixed[][]
		 */
		protected function getGeoServicesEntering(City $city, Address $oAddress = null)
		{
			$point_to = $oAddress ? $oAddress->coords() : null;

			if (!$point_to || $city->parentCity()->id() != $city->id())
				return [];

			$geoDP = new GeoDataProvider();

			$entering_services = $geoDP->getCityInnerBorders($city->id());
			if (!$entering_services)
				return [];

			// Массив выходных услуг по въезду.
			$services = [];

			foreach ($entering_services as $entering_service)
			{
				// Точка назначения внутри границ - выходим.
				// Разворачиваем координаты, потому что границы у нас хранятся в формате Яндекса - lon-lat.
				if (self::pointInPolygon(array($point_to[1], $point_to[0]), $entering_service['coords']))
				{
					$services[] = array(
						'service_id' => $entering_service['service_id'],
						'from_city_id' => $city->parentCity()->id(),
						'to_city_id' => $city->parentCity()->id(),
						'title' => $entering_service['service_title'],
						'amount' => 1,
					);
				}
			}
			return $services;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить информацию по адресу.
		 *
		 * @param string $address
		 * @param string $geo_service - сервис гео-кодера
		 *
		 * @return mixed[]|null Массив со следующими полями:
		 * - string 'address_clean' Адрес, очищенный ДаДатой от мусора.
		 * - City|null 'city' Город из нашей БД, если он был определён.
		 */
		public function getAddressData($address, $geo_service)
		{
			switch ($geo_service)
			{
				// для дадаты делаем по-старинке
				case self::GEO_SERVICE_DADATA:
					// Полную информацию по адресу даёт только ДаДата.
					// todo Возможно, есть смысл сразу включить получение координат.
					$address_data = $this->geocodeAddressFromDadata($address);
					if (!$address_data)
						return null;

					$address_data['city'] = $this->defineCityByFiasIds($address_data['city_fias_ids']);

					return $address_data;
				case self::GEO_SERVICE_YANDEX:
					return $this->geocodeAddressFromYandex($address);
			}

			return null;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить информацию по ФИАСу.
		 *
		 * @param string $fias_id Код ФИАСа.
		 *
		 * @return mixed[]|null Массив со следующими полями:
		 * - string 'address_clean' Адрес, очищенный ДаДатой от мусора.
		 * - City 'city' Город из нашей БД, если он был определён.
		 *
		 * @throws Exception
		 */
		public function getAddressDataByFiasId($fias_id)
		{
			$address_data = $this->geocodeFiasIdFromDadata($fias_id);
			if (!$address_data)
				return null;

			$address_data['city'] = $this->defineCityByFiasIds($address_data['city_fias_ids']);

			return $address_data;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Попытаться определить населённый пункт по списку кодов ФИАС.
		 * Список перебирается по порядку. Возвращается первый город, для которого будет найдено совпадение по ФИАС.
		 *
		 * @param string[] $city_fias_ids
		 * @return City|null
		 *
		 * @throws Exception
		 */
		protected function defineCityByFiasIds(array $city_fias_ids)
		{
			$city = null;

			// Перебираем ФИАСы от нижних уровней к верхним.
			foreach ($city_fias_ids as $fias)
			{
				// Ищем город по ФИАС.
				$search_result = Searcher::search($fias, array(Action::INDEX_CITY));

				if ($search_result[Action::INDEX_CITY])
				{
					$city_repository = new CityRepository();
					$city = $city_repository->findById(reset($search_result[Action::INDEX_CITY]));

					if ($city && $city->active())
						break;
				}
			}

			return $city;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Прямое геокодирование - найти координаты по адресу.
		 *
		 * @param float[]|string $address Адрес. Если передан массив, метод его же и вернёт, считая, что это уже координаты.
		 *
		 * @return float[]|null
		 */
		public function geocodeAddress($address)
		{
			if (!$address)
				return null;

			// Передали координаты, их и возвращаем.
			if (is_array($address))
				return $address;

			$result = null;

			switch (self::$geocode_service)
			{
				// Получение координат по адресу через ДаДату - плохая идея?
				case self::GEO_SERVICE_DADATA:
					$result = $this->geocodeAddressFromDadata($address, true);

					// Если сервис ничего не вернул или вернул ошибку, делаем запрос к альтернативному сервису.
					if (!$result)
					{
						SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $address, SaveGeoQueryAction::RESULT_ERROR, 'DaData не нашла координаты, пробуем через Яндекс');
						$result = $this->geocodeAddressFromYandex($address);
					}
				break;

				case self::GEO_SERVICE_YANDEX:
					$result = $this->geocodeAddressFromYandex($address, $result_code);

					// Если сервис ничего не вернул или вернул ошибку, делаем запрос к альтернативному сервису.
					if (!$result)
					{
						SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $address, SaveGeoQueryAction::RESULT_ERROR, 'Яндекс вернул ошибку, пробуем через DaData');
						$result = $this->geocodeAddressFromDadata($address, true);
					}
				break;

				case self::GEO_SERVICE_GOOGLE:
					$result = $this->geocodeAddressFromGoogle($address, $result_code);

					// Если сервис ничего не вернул или вернул ошибку, делаем запрос к альтернативному сервису.
					if (!$result)
					{
						SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $address, SaveGeoQueryAction::RESULT_ERROR, 'Google вернул ошибку, пробуем через Яндекс');
						$result = $this->geocodeAddressFromYandex($address);
					}
				break;
			}
			return isset($result['coords']) ? $result['coords'] : null;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить расстояние между точками.
		 *
		 * @param Address|float[]|string $from От какой точки считаем. Если передана строка, она будет геокодирована в координаты.
		 * @param Address|float[]|string $to До какой точки считаем. Если передана строка, она будет геокодирована в координаты.
		 * @param int $result_code Код результата (см. RESULT_CODE_...).
		 *
		 * @return float|null Расстояние В ОДИН КОНЕЦ. Чтобы получить расстояние для выезда, умножаем на 2.
		 */
		public function getDistance($from, $to, &$result_code = null)
		{
			// Если прислали объект типа Address, получаем координаты из него.
			// Если в переменных строки, прогоняем их через геокодинг.
			// Если в переменных уже координаты, методы вернут их без изменений.
			$from = ($from instanceof Address) ? $from->coords() : $this->geocodeAddress($from);
			$to = ($to instanceof Address) ? $to->coords() : $this->geocodeAddress($to);

			if (!$from || !$to)
				return null;

			$distance = null;

			switch (self::$route_service)
			{
				case self::GEO_SERVICE_YANDEX:
					for ($i = 0; $i < self::ROUTE_ERROR_RETRIES; ++$i)
					{
						$distance = $this->getDistanceFromYandex($from, $to, false, $result_code);
						// Если была ошибка сервиса или ответ получен, прекращаем цикл.
						// Если маршрут не был построен, пробуем ещё раз, потому что Яндекс может вернуть status: fail даже для нормальных точек.
						if ($result_code != self::RESULT_CODE_NO_RESULT)
							break 2;
					}

					// Если даже несколько попыток не дали результата, пробуем через Google.
					// Поправка: Гугл нас забанил, поэтому это бессмысленно.
					if ($result_code == self::RESULT_CODE_NO_RESULT)
						$distance = $this->getDistanceFromGoogle($from, $to, $result_code);
				break;

				case self::GEO_SERVICE_GOOGLE:
					$distance = $this->getDistanceFromGoogle($from, $to, $result_code);
				break;
			}
			return $distance;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Метод для получения списка подсказок от DaData.
		 * Суть в том, что DaData работает немного по-разному, когда мы запрашиваем 1 результат (count == 1) и когда больше 1.
		 * Когда мы запрашиваем больше 1 результата, она пытается найти подходящие адреса (т. е. ищет не по полному совпадению) и предлагает варианты.
		 * Т. е. работает так, как работают по умолчанию остальные гео-сервисы.
		 * Проблема в том, что в таком режиме она не возвращает координаты найденных точек.
		 * Когда мы запрашиваем 1 результат, она ищет по точному совпадению адреса.
		 * Предполагается, что запрос с count == 1 происходит только тогда, когда юзер выбрал конкретную подсказку, и мы запрашиваем дополнительную инфу по ней.
		 * В таком режиме она возвращает координаты точки.
		 * Поэтому для DaDat-ы мы передаём дополнительный параметр $is_selected (см. geocodeAddressFromDadata()),
		 * который указывает, выбран ли адрес из предложенного списка или нет.
		 * Так вот, если адрес не выбран (например, мы определяем координаты склада), то приходится делать 2 запроса.
		 * Сначала с count == 2, чтобы получить наиболее подходящие варианты адресов по запрашиваемой строке адреса.
		 * Затем мы берём точный адрес из первого варианта (как наиболее релевантный) и делаем второй запрос с count == 1.
		 * Он вернёт нам дополнительную инфу, в том числе координаты, которые нам и нужны.
		 *
		 * @param string $query Адрес или код ФИАС.
		 * @param bool $by_fias_id Поиск по коду ФИАС.
		 * @param int $count Максимальное количество вариантов в ответе.
		 * @param mixed[] $log Лог запроса, передаётся по ссылке. Сюда метод сложит инфу об ошибках и ворнингах, если они будут.
		 *
		 * @return null|mixed[][] Массив подсказок, которые выдал сервис.
		 */
		protected function geocodeAddressFromDadataQuery($query, $by_fias_id = false, $count = 2, &$log = [])
		{
			$params = array(
				'query' => $query,
				'count' => $count,
			);

			if ($by_fias_id)
				$url = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/address';
			else
				$url = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';

			$log['url'] = trim(urldecode($url . '?' . http_build_query($params)));
			$body = json_encode($params);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($body),
				'Accept: application/json',
				'Authorization: Token ' . self::API_KEY_DADATA,
			));
			$response = curl_exec($ch);
			$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);

			if ($response_code != 200)
			{
				$log += array(
					'result' => SaveGeoQueryAction::RESULT_ERROR,
					'response' => $response ?: "Код ответа - $response_code",
				);
				return null;
			}

			$response = json_decode($response, true);
			if (!$response)
			{
				$log += array(
					'result' => SaveGeoQueryAction::RESULT_ERROR,
					'response' => 'Некорректный ответ',
				);
				return null;
			}

			if (!isset($response['suggestions'][0]))
			{
				$log += array(
					'result' => SaveGeoQueryAction::RESULT_NOTICE,
					'response' => 'Адрес не найден',
				);
				return null;
			}

			$results = $response['suggestions'];
			return $results;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Запрос информации об адресе через ДаДату.
		 * Координатам ДаДата не богата (см. начало файла), зато богата ФИАСами.
		 * Данный метод лучше использовать только для получения кодов ФИАС по адресу.
		 *
		 * @param string $address Адрес.
		 *
		 * @param boolean $get_coords Получить координаты. Плохая идея, не стоит этого делать!
		 *
		 * @return mixed[]|null Массив со следующими полями:
		 * string 'address_clean' Адрес, очищенный ДаДатой от мусора.
		 * string[] 'city_fias_ids' Список ФИАС-кодов населённых пунктов от нижних уровней к верхним. По этому списку можно вынуть город из нашей БД.
		 * string 'street_fias_id' ФИАС улицы или ближайшей структуры уровнем выше (нас. пункта, города и т. д.).
		 * string 'house' Часть адреса с типом и номером дома и строения (т. е. то, что между улицей и квартирой).
		 * string 'street_fias_id_house' Ключ для кэша.
		 * float[]|null 'coords' Координаты точки или null, если гео-кодирование не удалось. На координаты от ДаДаты полагаться нельзя!
		 */
		protected function geocodeAddressFromDadata($address, $get_coords = false)
		{
			$log = null;
			$address_trimmed = trim($address);
			// Если в ДаДату слать строку без пробела в конце, она будет искать вхождение строки.
			// Если с пробелом в конце, то приоритет будет у отдельной строки.
			// Например, запрос "Свердловская обл, г Реж" вернёт первым результатом "Свердловская обл, г Екатеринбург, Режевской пер".
			// А вот "Свердловская обл, г Реж " вернёт именно город Реж.
			$address = $address_trimmed . ' ';

			// Если нужно получить координаты, необходимо сделать уточняющий запрос для определения точного адреса.
			if ($get_coords)
			{
				$suggestions = $this->geocodeAddressFromDadataQuery($address, false, 2, $log);

				if (!$suggestions)
				{
					SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], $log['result'], $log['response']);
					return null;
				}
				$address = $suggestions[0]['unrestricted_value'];
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_SUCCESS, "Уточнение адреса: $address");
			}

			// Теперь считаем, что по нашему адресу должна быть точная подсказка.
			// Запрашиваем с count == 1, чтобы получить координаты.
			$suggestions = $this->geocodeAddressFromDadataQuery($address, false, $get_coords ? 1 : 2, $log);
			// Ответа нет.
			if (!$suggestions)
			{
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], $log['result'], $log['response']);
				return null;
			}

			$result = $this->geocodeFromDadataProcessResult($suggestions);

			if (sizeof($suggestions) > 1 && $address_trimmed != $result['address_clean'])
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_NOTICE, 'Вернулось больше 1 точки');

			// При запросе с count == 1 координаты должны прийти, но на всякий случай проверим это.
			if ($get_coords)
			{
				// Лучше не запрашивать координаты у ДаДаты (см. начало файла).
				if (!$result['coords'])
					SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_WARNING, 'Пустые координаты');
				else
					SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_SUCCESS, $result['coords']);
			}
			SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_SUCCESS, 'Адрес распознан');

			return $result;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Запрос информации об адресе по коду ФИАС из ДаДаты.
		 *
		 * @param string $fias_id
		 *
		 * @return mixed[]
		 */
		protected function geocodeFiasIdFromDadata($fias_id)
		{
			$suggestions = $this->geocodeAddressFromDadataQuery($fias_id, true, 1, $log);
			// Ответа нет.
			if (!$suggestions)
			{
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], $log['result'], $log['response']);
				return null;
			}

			$result = $this->geocodeFromDadataProcessResult($suggestions);

			// Лучше не запрашивать координаты у ДаДаты (см. начало файла).
			if (!$result['coords'])
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_WARNING, 'Пустые координаты');
			else
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_SUCCESS, $result['coords']);

			SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $log['url'], SaveGeoQueryAction::RESULT_SUCCESS, 'ФИАС распознан');

			return $result;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить одну запись из присланных ДаДатой результатов, обработать её и вернуть только необходимые поля.
		 *
		 * @param mixed[][] $suggestions
		 *
		 * @return mixed[]
		 */
		protected function geocodeFromDadataProcessResult(array $suggestions)
		{
			$suggestion = reset($suggestions);
			$data = $suggestion['data'];

			$result = array(
				'fias_cache_id' => null,
			);

			// Чистый адрес, отфильтрованный ДаДатой.
			// Подойдёт для передачи далее в маршрутизатор, потому что ДаДата гораздо лучше переваривает мусор в адресе, чем, например, Яндекс.
			$result['address_clean'] = trim($suggestion['value']);

			// Коды ФИАС поселения, города и района. Фильтрованные, светлые.
			// Для определения города нужно перебирать их по порядку.
			$result['city_fias_ids'] = array_unique(array_filter(array(
				$data['settlement_fias_id'],
				$data['city_fias_id'],
				$data['region_fias_id'],
			)));

			// ФИАС улицы или того, что выше уровнем. Может использоваться для кэширования вместе с номером дома.
			$street_fias_id = array_merge(array_filter(array($data['street_fias_id'])), $result['city_fias_ids']);

			if ($street_fias_id)
			{
				$street_fias_id = reset($street_fias_id);
				// Дом и строение. Может использоваться для кэширования вместе с ФИАСом улицы.
				$house = implode(' ', array_filter(array($data['house_type'], $data['house'], $data['block_type'], $data['block'])));
				// Ключ для кэша.
				$result['fias_cache_id'] = implode('_', array_filter(array($street_fias_id, $house)));
			}

			// Дописываем координаты, если они есть.
			$result['coords'] = array_filter(array($data['geo_lat'], $data['geo_lon'])) ?: null;

			return $result;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Прямое геокодирование - найти координаты по адресу.
		 *
		 * @param string $address Адрес.
		 * @param int $result_code Код результата.
		 *
		 * @return mixed[]|null Координаты точки или null, если гео-кодирование не удалось.
		 * ВАЖНО: Яндекс по умолчанию принимает и возвращает координаты в формате долгота-широта (long, lat).
		 * Поскольку у нас в БД координаты хранятся в формате (lat, long), данный метод разворачивает ответ от Яндекса
		 * и выдаёт их именно в таком виде (lat, long).
		 */
		protected function geocodeAddressFromYandex($address, &$result_code = null)
		{
			$params = array(
				'apikey' => self::API_KEY_YANDEX_GEOCODE,
				'format' => 'json',
				'geocode' => $address,
				//'sco' => 'latlong',
			);
			$url = 'https://geocode-maps.yandex.ru/1.x/?' . http_build_query($params);
			$url_decoded = trim(urldecode($url));

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$response = curl_exec($ch);
			$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);

			if ($response_code != 200)
			{
				$result_code = self::RESULT_CODE_NO_RESPONSE;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, $response ?: "Код ответа - $response_code");
				return null;
			}

			$response = json_decode($response, true);
			if (!$response)
			{
				$result_code = self::RESULT_CODE_ERROR;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Некорректный ответ');
				return null;
			}

			if (!isset($response['response']['GeoObjectCollection']['featureMember'][0]))
			{
				$result_code = self::RESULT_CODE_NO_RESULT;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Адрес не найден');
				return null;
			}

			$results = $response['response']['GeoObjectCollection']['featureMember'];
			if (sizeof($results) > 1)
			{
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_NOTICE, 'Вернулось больше 1 точки');
			}

			$coords = $results[0]['GeoObject']['Point']['pos'];
			$coords = explode(' ', $coords);
			// Яндекс возвращает координаты строками и наоборот. Конвертим в числа и разворачиваем.
			$coords = array((float) $coords[1], (float) $coords[0]);

			SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_SUCCESS, $coords);

			$result_code = self::RESULT_CODE_SUCCESS;
			return array('coords' => $coords);
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить расстояние через API Яндекса.
		 *
		 * @param float[] $from От какой точки считаем.
		 *
		 * @param float[] $to До какой точки считаем.
		 *
		 * @param bool $use_deviation Задел на будущее.
		 * Иногда точка, которую мы рассчитали (например, на границе города, от которой считается выезд),
		 * находится в таком месте, что от неё не получается построить маршрут (например, на воде или в лесу).
		 * Тогда можно делать несколько запросов с небольшим разбросом по координатам и брать самый подходящий из найденных.
		 * Пока это не реализовано.
		 *
		 * @param int $result_code Код результата.
		 *
		 * @return float|null
		 */
		protected function getDistanceFromYandex(Array $from, Array $to, $use_deviation = false, &$result_code = null)
		{
			$this->last_route_service = self::GEO_SERVICE_YANDEX;

			$from = implode(',', $from);
			$to = implode(',', $to);

			// Чтобы минимизировать влияние пробок на маршрут, указываем ночь завтрашнего дня.
			$date = new DateTime();
			$date = $date->modify('+1 day')->setTime(2, 0, 0);
			$timestamp = $date->getTimestamp();

			$params = array(
				'apikey' => self::API_KEY_YANDEX_ROUTE,
				'waypoints' => $from . '|' . $to,
				'departure_time' => $timestamp,
				'avoid_tolls' => 1,
			);
			$url = 'https://api.routing.yandex.net/v1.0.0/route?' . http_build_query($params);
			$url_decoded = trim(urldecode($url));

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$response = curl_exec($ch);
			$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);

			if ($response_code != 200)
			{
				$result_code = self::RESULT_CODE_NO_RESPONSE;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, $response ?: "Код ответа - $response_code");
				return null;
			}

			$response = json_decode($response, true);
			if (!$response)
			{
				$result_code = self::RESULT_CODE_ERROR;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Некорректный ответ');
				return null;
			}

			if (!isset($response[SaveGeoQueryAction::TYPE_ROUTE]['legs'][0]['steps'][0]))
			{
				$result_code = self::RESULT_CODE_NO_RESULT;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Не удалось построить маршрут');
				return null;
			}

			$distance = 0;
			//$polyline = [];

			$steps = $response[SaveGeoQueryAction::TYPE_ROUTE]['legs'][0]['steps'];
			foreach ($steps as $step)
			{
				$distance += $step['length'];
				//$polyline = array_merge($polyline, $step['polyline']['points']);
			}

			SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_SUCCESS, $distance);

			$distance = ceil($distance / 1000);

			$result_code = self::RESULT_CODE_SUCCESS;
			return $distance;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Прямое геокодирование - найти координаты по адресу.
		 *
		 * @param string $address Адрес.
		 * @param int $result_code Код результата.
		 *
		 * @return mixed[]|null Координаты точки или null, если гео-кодирование не удалось.
		 */
		protected function geocodeAddressFromGoogle($address, &$result_code = null)
		{
			$params = array(
				'key' => self::API_KEY_GOOGLE,
				'address' => $address,
			);
			$url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($params);
			$url_decoded = trim(urldecode($url));

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$response = curl_exec($ch);
			$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);

			if ($response_code != 200)
			{
				$result_code = self::RESULT_CODE_NO_RESPONSE;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, $response ?: "Код ответа - $response_code");
				return null;
			}

			$response = json_decode($response, true);
			if (!$response)
			{
				$result_code = self::RESULT_CODE_ERROR;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Некорректный ответ');
				return null;
			}

			if (!isset($response['results'][0]))
			{
				$result_code = self::RESULT_CODE_NO_RESULT;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Адрес не найден');
				return null;
			}

			$results = $response['results'];
			if (sizeof($results) > 1)
			{
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_NOTICE, 'Вернулось больше 1 точки');
			}

			$coords = $results[0]['geometry']['location'];
			$coords = array($coords['lat'], $coords['lng']);

			SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_GEOCODE, $url_decoded, SaveGeoQueryAction::RESULT_SUCCESS, $coords);

			$result_code = self::RESULT_CODE_SUCCESS;
			return array('coords' => $coords);
		}
		//--------------------------------------------------------------------------------

		/**
		 * Получить расстояние через API Google.
		 *
		 * @param float[] $from От какой точки считаем.
		 * @param float[] $to До какой точки считаем.
		 * @param int $result_code Код результата (см. RESULT_CODE_...).
		 *
		 * @return float|null
		 */
		protected function getDistanceFromGoogle($from, $to, &$result_code = null)
		{
			$this->last_route_service = self::GEO_SERVICE_GOOGLE;

			if (is_array($from))
				$from = implode(',', $from);

			if (is_array($to))
				$to = implode(',', $to);

			$params = array(
				'key' => self::API_KEY_GOOGLE,
				'origin' => $from,
				'destination' => $to,
				'units' => 'metric',
				'alternatives' => 1,
			);
			$url = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query($params);
			$url_decoded = trim(urldecode($url));

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$response = curl_exec($ch);
			$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);

			if ($response_code != 200)
			{
				$result_code = self::RESULT_CODE_NO_RESPONSE;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, $response ?: "Код ответа - $response_code");
				return null;
			}

			$response = json_decode($response, true);
			if (!$response)
			{
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Некорректный ответ');
				return null;
			}

			if (!isset($response['routes'][0]['legs'][0]['distance']['value']))
			{
				$result_code = self::RESULT_CODE_NO_RESULT;
				SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_ERROR, 'Не удалось построить маршрут');
				return null;
			}

			$distance = $response['routes'][0]['legs'][0]['distance']['value'];
			SaveGeoQueryAction::log(SaveGeoQueryAction::TYPE_ROUTE, $url_decoded, SaveGeoQueryAction::RESULT_SUCCESS, $distance);

			$distance = ceil($distance / 1000);

			$result_code = self::RESULT_CODE_SUCCESS;
			return $distance;
		}
		//--------------------------------------------------------------------------------

		/**
		 * Проверить, находится ли точка внутри или на границе полигона.
		 * Используется, чтобы определить, является ли адрес внутри определённой зоны (например, внутри Садового кольца).
		 * Взято отсюда и адаптировано:
		 * http://assemblysys.com/php-point-in-polygon-algorithm/
		 *
		 * @param float[] $point Точка.
		 * @param float[][] $polygon Массив точек полигона.
		 *
		 * @return boolean
		 */
		public static function pointInPolygon(Array $point, Array $polygon)
		{
			if (sizeof($polygon) < 3)
				return false;

			// Переводим простой массив в ассоциативный (он используется в методе).
			$point = self::pointToCoordinates($point);
			$vertices = [];
			foreach ($polygon as $vertex)
				$vertices[] = self::pointToCoordinates($vertex);

			// Проверяем, вдруг точка совпадает с одной из вершин полигона.
			if (self::pointOnVertex($point, $vertices) == true)
				return true;//"vertex";

			// Теперь проверяем, находится ли точка на границе или внутри полигона.
			$intersections = 0;
			$vertices_count = count($vertices);

			for ($i = 1; $i < $vertices_count; ++$i)
			{
				$vertex1 = $vertices[$i - 1];
				$vertex2 = $vertices[$i];

				// Проверяем горизонтальную границу.
				if ($vertex1['y'] == $vertex2['y'] && $vertex1['y'] == $point['y']
					&& $point['x'] > min($vertex1['x'], $vertex2['x']) && $point['x'] < max($vertex1['x'], $vertex2['x']))
				{
					return true;//"boundary";
				}

				// Проверяем остальные границы.
				if ($point['y'] > min($vertex1['y'], $vertex2['y']) && $point['y'] <= max($vertex1['y'], $vertex2['y'])
					&& $point['x'] <= max($vertex1['x'], $vertex2['x']) && $vertex1['y'] != $vertex2['y'])
				{
					$xinters = ($point['y'] - $vertex1['y']) * ($vertex2['x'] - $vertex1['x']) / ($vertex2['y'] - $vertex1['y']) + $vertex1['x'];
					if ($xinters == $point['x'])
						return true;//"boundary";

					if ($vertex1['x'] == $vertex2['x'] || $point['x'] <= $xinters)
						++$intersections;
				}
			}
			// Если количество пересечений с границами полигона нечётное, он находится внутри полигона.
			if ($intersections % 2 != 0)
				return true;//"inside";
			else
				return false;//"outside";
		}
		//--------------------------------------------------------------------------------

		/**
		 * @param float[] $point
		 * @param float[][] $vertices
		 *
		 * @return bool
		 */
		protected static function pointOnVertex(Array $point, Array $vertices)
		{
			foreach ($vertices as $vertex)
			{
				if ($point == $vertex)
					return true;
			}
			return false;
		}
		//--------------------------------------------------------------------------------

		/**
		 * @param float[] $point
		 *
		 * @return float[]
		 */
		protected static function pointToCoordinates($point)
		{
			return array('x' => $point[0], 'y' => $point[1]);
		}
		//--------------------------------------------------------------------------------

		/**
		 * Находим точку полигона, ближайшую к заданным координатам.
		 * Используется для определения расстояния от адреса до границы города.
		 * От ближайшей точки границ города затем строится маршрут для определения километража выезда.
		 *
		 * @param float[] $point
		 * @param float[][] $polygon
		 *
		 * @return mixed[]|null Возвращаем координаты ближайшей точки и расстояние до неё.
		 */
		protected function nearestPointOfPolygon(Array $point, Array $polygon)
		{
			if (sizeof($polygon) < 2)
				return null;

			$last_index = sizeof($polygon) - 1;
			$min_distance = PHP_INT_MAX;//PHP_FLOAT_MAX;
			$nearest_polygon_point = null;

			for ($i = 0; $i < $last_index; ++$i)
			{
				$line_begin = $polygon[$i];
				$line_end = $polygon[$i + 1];

				list($distance, $polygon_point) = $this->nearestPointOfLine($point, $line_begin, $line_end);

				if ($distance < $min_distance)
				{
					$min_distance = $distance;
					$nearest_polygon_point = $polygon_point;
				}
			}
			return array(
				'distance' => $min_distance,
				'point' => $nearest_polygon_point,
			);
		}
		//--------------------------------------------------------------------------------

		/**
		 * Найти координаты ближайшей точки отрезка к заданным координатам.
		 * Используется для определения расстояния от адреса до границы города (в цикле перебора отрезков, из которых эти границы состоят).
		 * Взято отсюда и адаптировано:
		 * https://gis.stackexchange.com/a/44864
		 *
		 * @param float[] $point Точка, от которой мы ищем ближайшее расстояние.
		 * @param float[] $segment_begin Начало отрезка.
		 * @param float[] $segment_end Конец отрезка.
		 *
		 * @return array Координаты точки на отрезке, ближайшей к $point.
		 */
		protected function nearestPointOfLine(Array $point, Array $segment_begin, Array $segment_end)
		{
			list($point_x, $point_y) = $point;
			list($start_x, $start_y) = $segment_begin;
			list($end_x, $end_y) = $segment_end;

			$r_numerator = ($point_x - $start_x) * ($end_x - $start_x) + ($point_y - $start_y) * ($end_y - $start_y);
			$r_denominator = ($end_x - $start_x) * ($end_x - $start_x) + ($end_y - $start_y) * ($end_y - $start_y);
			$r = $r_denominator ? ($r_numerator / $r_denominator) : 0;

			$px = $start_x + $r * ($end_x - $start_x);
			$py = $start_y + $r * ($end_y - $start_y);

			$s = $r_denominator ? ((($start_y - $point_y) * ($end_x - $start_x) - ($start_x - $point_x) * ($end_y - $start_y)) / $r_denominator) : 0;

			$distanceLine = abs($s) * sqrt($r_denominator);

			$closest_point_on_segment_X = $px;
			$closest_point_on_segment_Y = $py;

			if (($r >= 0) && ($r <= 1))
			{
				$distance_segment = $distanceLine;
			}
			else
			{
				$dist1 = ($point_x - $start_x) * ($point_x - $start_x) + ($point_y - $start_y) * ($point_y - $start_y);
				$dist2 = ($point_x - $end_x) * ($point_x - $end_x) + ($point_y - $end_y) * ($point_y - $end_y);
				if ($dist1 < $dist2)
				{
					$closest_point_on_segment_X = $start_x;
					$closest_point_on_segment_Y = $start_y;
					$distance_segment = sqrt($dist1);
				}
				else
				{
					$closest_point_on_segment_X = $end_x;
					$closest_point_on_segment_Y = $end_y;
					$distance_segment = sqrt($dist2);
				}
			}
			return array($distance_segment, array($closest_point_on_segment_X, $closest_point_on_segment_Y));
		}
		//--------------------------------------------------------------------------------

		/**
		 * Расчёт расстояния между двумя точками (координатами) по формуле Винсенти (т. е. с учётом шарообразности планеты).
		 * Метод может быть использован, чтобы определить самый близкий (географически) филиал для нового нас. пункта, дабы затем привязать его к этому филиалу.
		 * Источник:
		 * https://stackoverflow.com/a/10054282/1456920
		 *
		 * @param float[] $from Координаты первой точки (широта, долгота).
		 * @param float[] $to Координаты второй точки (широта, долгота).
		 * @param float $earth_radius Радиус Земли в километрах.
		 *
		 * @return float Расстояние в тех единицах, в которых указан радиус Земли (в нашем случае это километры).
		 */
		public static function getCircleDistance($from, $to, $earth_radius = 6371.0)
		{
			// Конвертим градусы в радианы.
			$lat_from = deg2rad($from[0]);
			$lon_from = deg2rad($from[1]);
			$lat_to = deg2rad($to[0]);
			$lon_to = deg2rad($to[1]);

			$lon_delta = $lon_to - $lon_from;
			$a = pow(cos($lat_to) * sin($lon_delta), 2)
				+ pow(cos($lat_from) * sin($lat_to) - sin($lat_from) * cos($lat_to) * cos($lon_delta), 2);
			$b = sin($lat_from) * sin($lat_to) + cos($lat_from) * cos($lat_to) * cos($lon_delta);

			$angle = atan2(sqrt($a), $b);

			return $angle * $earth_radius;
		}
		//--------------------------------------------------------------------------------
	}
