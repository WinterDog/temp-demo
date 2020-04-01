/**
 * @file Миксин для простых и анимированных инпутов.
 */

export default {
	props: {
		classes: {
			type: [ Array ],
			default: () => [],
		},
		disabled: {
			type: [ Boolean, Number, String ],
			default: null,
		},
		max: {
			type: [ Number, String ],
			default: null,
		},
		maxlength: {
			type: [ Number, String ],
			default: null,
		},
		min: {
			type: [ Number, String ],
			default: null,
		},
		name: {
			type: [ String ],
			default: null,
		},
		pattern: {
			type: [ String ],
			default: '[0-9,\.]*',
		},
		placeholder: {
			type: [ Number, String ],
			default: null,
		},
		readonly: {
			type: [ Boolean, Number, String ],
			default: null,
		},
		step: {
			type: [ Number, String ],
			default: null,
		},
		value: {
			type: [ Number, String ],
			default: null,
		},
	},
	//----------------------------------------------------------------------------------------------------------------------

	methods: {
		/**
		 * @public
		 * Поставить фокус в поле. Прокси-метод для вызова снаружи.
		 */
		focus: function ()
		{
			$(this.$el).trigger('focus');
		},
		//----------------------------------------------------------------------------------------------------------------------

		focusOut: function ()
		{
			/*if (this.max === null)
				return;

			const value = parseFloat(this.value) || 0;
			if (!value)
				return;

			if (value > this.max)
				this.value = this.max;*/
		},
		//----------------------------------------------------------------------------------------------------------------------
	},
	//----------------------------------------------------------------------------------------------------------------------

	mounted: function ()
	{
		// todo Этому вообще тут не место, должно быть в animated-input.vue.
		if ($(this.$el).closest('.animated-label').length)
		{
			bindAnimatedLabels(this.$el);
			// Атрибут используется в main.js, чтобы повторно не инициализировать уже инициализованные анимированные инпуты.
			$(this.$el).attr('data-animated-init', 1);
		}
	},
	//----------------------------------------------------------------------------------------------------------------------

	data: function ()
	{
		return {
			local_value: this.value || null,
		};
	},
	//----------------------------------------------------------------------------------------------------------------------
};
