/**
 * Преобразовать переданный объект или строку в объект moment, если это возможно.
 * Если передан объект moment, вернётся его копия.
 *
 * @param {Date|moment|string} value Можно передать строку в форматах YYYY-MM-DD или DD.MM.YYYY (+ время до секунд опционально), объекты moment или Date.
 *
 * @param {boolean} with_time Вынимать ли время из переданной строки или объекта.
 * Если передано false, то время в выходном объекте всегда будет равно "00:00:00", даже если в параметре moment-объект со временем.
 *
 * @returns {moment|null}
 */
function getMoment(value, with_time = true)
{
	if (!value)
		return null;

	let value_moment = null;

	if (typeof value === 'string')
	{
		// Формат, который передадим moment-у для создания объекта. Добавляем время, если нужно.
		const time_format = `${with_time ? ' HH:mm:ss' : ''}`;

		// Пробуем в формате БД.
		value_moment = moment(value, `YYYY-MM-DD${time_format}`);

		// Пробуем в человеческом формате.
		if (!value_moment.isValid())
			value_moment = moment(value, `DD.MM.YYYY${time_format}`);

		// Невалидный объект - возвращаем null.
		if (!value_moment.isValid())
			value_moment = null;
	}
	else
	{
		// Объект moment - просто создаём его копию.
		if (value instanceof moment)
		{
			if (value.isValid())
				value_moment = value.clone();
		}
		// Объект Date - создаём из него moment.
		else if (value instanceof Date)
			value_moment = moment(value);

		if (value_moment)
		{
			// Невалидный объект - возвращаем null.
			if (!value_moment.isValid())
				value_moment = null;
			// Если время не нужно, сбрасываем его.
			else if (!with_time)
				value_moment.startOf('d');
		}
	}

	return value_moment;
};

export { getMoment };
