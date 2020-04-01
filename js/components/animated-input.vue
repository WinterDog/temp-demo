/**
 * @file Поле с анимированным лейблом.
 */

<template>
	<input ref="input" v-if="type === 'number'" class="form-control" :class="classes" :disabled="!!disabled" :max="max" :maxlength="maxlength" :min="min" :name="name" :pattern="pattern" :placeholder="placeholder" :readonly="!!readonly" :step="step" type="number" v-model.number="local_value" @input="update();">
	<input ref="input" v-else-if="type === 'password'" class="form-control" :class="classes" :disabled="!!disabled" :maxlength="maxlength" :name="name" :placeholder="placeholder" :readonly="!!readonly" type="password" v-model.trim="local_value" @focusout="focusOut();" @input="update();">
	<input ref="input" v-else-if="type === 'email'" class="form-control" :class="classes" :disabled="!!disabled" :maxlength="maxlength" :name="name" :placeholder="placeholder" :readonly="!!readonly" type="email" v-model.trim="local_value" @focusout="focusOut();" @input="update();">
	<input ref="input" v-else class="form-control" :class="classes" :disabled="!!disabled" :maxlength="maxlength" :name="name" :placeholder="placeholder" :readonly="!!readonly" type="text" v-model.trim="local_value" @focusout="focusOut();" @input="update();">
</template>

<script>
	import InputMixin from '../mixins/input';

	export default {
		mixins: [ InputMixin ],
		//----------------------------------------------------------------------------------------------------------------------

		props: {
			type: {
				type: [ String ],
				default: 'text',
			},
		},
		//----------------------------------------------------------------------------------------------------------------------

		methods: {
			update: function ()
			{
				this.$emit('input', this.local_value);
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------

		watch: {
			'value': function (value)
			{
				// Если значение не изменилось, ничего делать не нужно.
				if (value == this.local_value)
					return;

				this.local_value = value;

				// todo Анимированные лейблы.
				// Обработка анимированных лейблов (которые уезжают наверх при клике внутри поля или заполненном значении и возвращаются назад при пустом поле и focusout)
				// сейчас происходит в app.js (см bindAnimatedLabels()).
				// Хорошо бы перенести эту логику сюда.
				this.$nextTick(() =>
				{
					$(this.$el).trigger('gd.focus');
				});
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------
	};
</script>
