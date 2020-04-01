/**
 * @file Форма авторизации.
 */

<template>
	<div>
		<div class="row">
			<div class="col-sm-8">
				<div v-show="out.phone_over_email">
					<phone-input ref="phone" margin_bottom_0="1" v-model="out.phone"></phone-input>

					<div class="row margin-b-sm">
						<div class="col-sm-8 col-sm-offset-4">
							<a class="margin-t-sm small" href="javascript:" @click.left="out.phone_over_email = 0;">
								Вход по адресу эл. почты / логину
							</a>
						</div>
					</div>
				</div>

				<div class="form-group animated-label margin-b-0" v-show="!out.phone_over_email">
					<label>
						<span class="flowing-label">Электронная почта или логин</span>
						<animated-input ref="email" maxlength="256" type="text" v-model="out.email"></animated-input>
					</label>

					<div class="margin-b-sm">
						<a class="small" href="javascript:" @click.left="out.phone_over_email = 1;">
							Вход по номеру телефона
						</a>
					</div>
				</div>
			</div>

			<div class="col-sm-4">
				<div class="form-group animated-label margin-b-0">
					<label>
						<span class="flowing-label">Пароль</span>
						<animated-input ref="password" maxlength="128" type="password" v-model="out.password" v-show="!password_visible"></animated-input>
						<animated-input ref="password_visible" maxlength="128" type="text" v-model="out.password" v-show="password_visible"></animated-input>
						<a class="password-icon" href="javascript:" @click.left="password_visible = !password_visible;">
							<span v-show="!password_visible"><span class="ion-eye"></span></span>
							<span v-show="password_visible"><span class="ion-eye-disabled"></span></span>
						</a>
					</label>

					<div class="margin-b-sm">
						<a class="small" href="javascript:" @click.left="forgotClick();">
							Забыли пароль?
						</a>
					</div>
				</div>
			</div>
		</div>

		<div class="message-box-wrap margin-t-xs" v-show="error_show">
			<div class="msg error-msg">
				<span v-show="error.forgot">
					Проверьте введёные данные. <br><a class="small" href="javascript:" @click.left="forgotClick();">Забыли пароль?</a>
				</span>
				<span v-show="error.phone_sent">
					Пароль был отправлен на указанный телефон.
					<br>
					СМС долго не приходит?
					<a href="javascript:" @click.left="forgotEmailClick();">Укажите email</a>,
					и мы вышлем туда пароль.
				</span>
				<span v-show="error.email_sent">
					Пароль был отправлен на указанную электронную почту.
				</span>
				<span v-show="error.text">{{ error.text }}</span>
			</div>
		</div>
	</div>
</template>

<script>
	import AnimatedInput from 'animated-input.vue';
	import PhoneInput from 'phone-input.vue';

	export default {
		components: {
			AnimatedInput,
			PhoneInput,
		},
		//----------------------------------------------------------------------------------------------------------------------

		props: {
			// Через стандартный v-model возвращаем данные, введённые в форму.
			value: {
				type: [ Object ],
				default: () => {},
			},
		},
		//----------------------------------------------------------------------------------------------------------------------

		methods: {
			/**
			 * @public
			 * Асинхронный сабмит формы.
			 *
			 * @returns {Promise}
			 */
			submit: async function ()
			{
				return new Promise((resolve, reject) =>
				{
					// Сбрасываем ошибки.
					this.clearErrors();

					// Проверяем заполненность полей.
					if ((this.out.phone_over_email && this.out.phone == '')
						|| (!this.out.phone_over_email && this.out.email == '')
						|| (this.out.password == ''))
					{
						this.error.text = 'Укажите логин и пароль.';
						return reject(new Error('Не указан логин и/или пароль.'));
					}

					// Лочим всю страницу.
					blockElement();

					const params = $.extend({
						action: 'Login',
					}, this.out);

					$.post(script_url, params, (response) =>
					{
						// Разлачиваем страницу.
						unblockElement();

						const user = response ? JSON.parse(response) : null;
						// Если ответ корректен, резолвим промис с ним.
						if (user && user.user_id)
						{
							resolve(user);
						}
						/*else if (user && user.route && user.login_type == 2)
						{
							document.location.href = user.route;
							//$('#update-auth').css({'display' : 'flex'});
							//$.unblockUI();
						}*/
						else
						{
							// Иначе выдаём ошибку и реджектим промис.
							this.error.forgot = true;
							reject(new Error('Логин и/или пароль указаны неверно.'));
							//error();
						}
					});
				});
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Показать сообщение о том, что письмо с паролем отправлено на почту.
			 */
			showMessageEmailSent: function ()
			{
				this.clearErrors();
				this.error.email_sent = true;
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Показать сообщение о том, что пароль выслан на телефон.
			 */
			showMessagePhoneSent: function ()
			{
				this.clearErrors();
				this.error.phone_sent = true;
				$(this.$refs['password'].$el).trigger('focus');
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Поставить фокус ввода в поле с паролем.
			 */
			focusPassword: function ()
			{
				const ref = this.password_visible ? 'password_visible' : 'password';

				$(this.$refs[ref].$el).trigger('focus');
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Сбросить ошибки.
			 */
			clearErrors: function ()
			{
				this.error.forgot = false;
				this.error.email_sent = false;
				this.error.phone_sent = false;
				this.error.text = '';
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Переключаем на форму "Забыли пароль?"
			 */
			forgotClick: function ()
			{
				this.update();
				this.$emit('forgot');
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * На форме "Забыли пароль?" переключаем на ввод почты.
			 */
			forgotEmailClick: function ()
			{
				this.out.phone_over_email = 0;
				this.forgotClick();
			},
			//----------------------------------------------------------------------------------------------------------------------

			update: function ()
			{
				this.$emit('input', $.extend({}, this.out));
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------

		computed: {
			/**
			 * @returns {boolean} Нужно ли показать какую-либо ошибку.
			 */
			error_show: function ()
			{
				return this.error.forgot || this.error.email_sent || this.error.phone_sent || !!this.error.text;
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------

		watch: {
			/**
			 * Переключили ввод с почты на телефон или обратно.
			 */
			'out.phone_over_email': function ()
			{
				/*this.$nextTick(() =>
				{
					if (value === 1)
						this.$refs['phone'].focus();
					else
						this.$refs['email'].focus();
				});*/
				this.update();
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Если пароль становится видимым / скрытым, фокусируемся на поле с задержкой (иначе не работает).
			 */
			'password_visible': function ()
			{
				this.$nextTick(() =>
				{
					this.focusPassword();
				});
			},
			//----------------------------------------------------------------------------------------------------------------------

			'value': {
				deep: true,
				handler: function (value)
				{
					this.out = $.extend({}, value);
				},
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------

		data: function ()
		{
			return {
				// Выходной объект с данными.
				out: {
					// Адрес эл. почты.
					email: '',
					// Пароль.
					password: '',
					// Телефон.
					phone: '',
					// Если 1, то выбрана авторизация по телефону. Иначе - по эл. адресу.
					phone_over_email: 0,
				},
				// Отображение ошибок.
				error: {
					// Показывать ссылку "Забыли пароль?"
					forgot: false,
					// Сообщение о том, что пароль отправлен на почту.
					email_sent: false,
					// То же самое для телефона.
					phone_sent: false,
					// Текст ошибки (например, не заполнено такое-то поле и т. п.).
					text: null,
				},
				// Показать пароль (кнопка внутри поля).
				password_visible: false,
			};
		},
		//----------------------------------------------------------------------------------------------------------------------
	};
</script>
