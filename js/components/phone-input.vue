/**
 * @file Блок для ввода номера телефона с кодом страны.
 */

<template>
	<div class="row form-group" :class="{ 'margin-b-0': !!margin_bottom_0 }" ref="wrap">
		<div class="col-xs-4">
			<div class="country-flag-list margin-b-0">
				<label>Код <span class="hidden-xs">страны</span></label>
				<nice-select ref="country_code" :disabled="disabled" id_field="code" :items="country_codes" title_field="code" v-model="country_code"></nice-select>
			</div>
		</div>
		<div class="col-xs-8">
			<div class="animated-label margin-b-0">
				<label>
					<span class="static-label">Номер телефона</span>
					<masked-input class="form-control" :disabled="disabled" :mask="country_code.mask" :placeholder="placeholder" ref="number" type="tel" v-model="number" @focusout.native="focusout();" @input="update();"></masked-input>
				</label>
			</div>
		</div>
	</div>
</template>

<script>
	import StringMask from 'string-mask';
	// Внутри используется компонент для "маскировочного" ввода.
	import MaskedInput from 'vue-masked-input';
	import NiceSelect from 'nice-select.vue';

	export default {
		components: {
			MaskedInput,
			NiceSelect,
		},
		//----------------------------------------------------------------------------------------------------------------------

		props: {
			disabled: {
				type: [ Boolean, Number, String ],
				default: false,
			},
			margin_bottom_0: {
				type: [ Boolean, Number, String ],
				default: false,
			},
			value: {
				type: [ String ],
				default: '',
			},
		},
		//----------------------------------------------------------------------------------------------------------------------

		methods: {
			/**
			 * @public
			 * Поставить фокус в поле.
			 */
			focus: function ()
			{
				this.$refs['number'].$el.focus();
			},
			//----------------------------------------------------------------------------------------------------------------------

			setValue: function (value)
			{
				value = value.trim() || '';

				if (value.length > 0 && value[0] !== '+')
					value = '+' + value;

				this.country_code = this.defineCountryCode(value);

				// Применяем маску к номеру, чтобы инпут корректно обработал его.
				const string_mask = new StringMask(this.country_code.mask.replace(/1/g, '0'));
				this.number = value.replace(this.country_code.code, '').replace(/\D/g, '');
				this.number = string_mask.apply(this.number);
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Определить код страны по переданному номеру.
			 *
			 * @param {string} value Номер телефона с кодом страны.
			 * @returns {object} Код страны в виде объекта.
			 */
			defineCountryCode: function (value)
			{
				for (const item of this.country_codes)
				{
					// Ищем подходящую маску по коду страны.
					if (value.indexOf(item.code) === 0)
						return item;
				}
				// Ставим по дефолту '+7'.
				return this.country_codes[0];
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * @returns {string} Чистое значение поля, только плюс и цифры.
			 */
			getRaw: function (value)
			{
				if (value === null)
					value = '';

				return value.replace(/[^+\d]/g, '');
			},
			//----------------------------------------------------------------------------------------------------------------------

			/*applyMask: function (number, mask)
			{
				const raw_mask = mask.replace(/[^0-9]/g, '');
				if (number.length !== raw_mask.length)
					return '';

				let masked = '';

				for (let i = 0; i < mask.length; ++i)
				{
					if (mask[i] === '1')
					{
						masked += number[0];
						number = number.slice(1);
					}
					else
						masked += mask[i];
				}
				return masked;
			},*/
			//----------------------------------------------------------------------------------------------------------------------

			checkMaskLength: function ()
			{
				return (this.getRaw(this.number).length === this.country_code.raw_length);
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Если телефон введён не до конца, то при убирании фокуса поле очистится (силами плагина).
			 * Соответственно, нам нужно уведомить об этом внешний мир, вызывав update().
			 */
			focusout: function ()
			{
				if (!this.checkMaskLength())
				{
					this.number = '';
					this.update();
				}
			},
			//----------------------------------------------------------------------------------------------------------------------

			update: function ()
			{
				const raw_phone = this.getRaw(this.number);
				this.raw_number = this.country_code.code + raw_phone;

				if (raw_phone == '' || this.checkMaskLength())
				{
					let out = '';

					if (raw_phone != '')
						out = this.country_code.code + ' ' + this.number;

					this.$emit('input', out);
				}
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------

		created: function ()
		{
			this.setValue(this.value);
		},
		//----------------------------------------------------------------------------------------------------------------------

		mounted: function ()
		{
			const $wrap = $(this.$refs.wrap);
			bindAnimatedLabels($wrap);
		},
		//----------------------------------------------------------------------------------------------------------------------

		watch: {
			'country_code.code': function ()
			{
				this.update();
			},
			//----------------------------------------------------------------------------------------------------------------------

			'value': function (value)
			{
				const value_raw = this.getRaw(value);

				if (value_raw === this.raw_number)
					return;

				this.setValue(value_raw);

				/*let phone_in = value.split('(');

				if (phone_in.length === 2)
				{
					this.country_code = phone_in[0];
					this.number = '(' + phone_in[1];
				}*/
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------

		computed: {
			placeholder: function ()
			{
				if (!this.country_code.mask)
					return '';

				return this.country_code.mask.replace(/1/g, '_');
			},
			//----------------------------------------------------------------------------------------------------------------------

			raw: function ()
			{
				return raw;
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------

		data: function ()
		{
			// Список кодов разных стран и масок, которые должны применяться к номеру.
			const country_codes = [
				{
					code: '+7',
					mask: '(111) 111-11-11',
					raw_length_coded: 12,
					raw_length: 10,
				},
				{
					code: '+375',
					mask: '(11) 111-11-11',
					raw_length_coded: 13,
					raw_length: 9,
				},
				{
					code: '+7',
					mask: '(111) 111-11-11',
					raw_length_coded: 12,
					raw_length: 10,
				},
				{
					code: '+996',
					mask: '(111) 111-11-11',
					raw_length_coded: 14,
					raw_length: 10,
				},
				{
					code: '+374',
					mask: '(11) 111-111',
					raw_length_coded: 12,
					raw_length: 8,
				},
			];

			return {
				country_codes: country_codes,
				country_code: country_codes[0],
				number: '',
				raw_number: '',
			};
		},
		//----------------------------------------------------------------------------------------------------------------------
	};
</script>
