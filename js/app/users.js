window.AppUsers = function (data = {})
{
	return new Vue({
		delimiters: ['<<', '>>'],
		el: '#vue-app',
		data: $.extend(true, {
			users: [],
			dropzone_options: $.extend({
				url: '/?page=Upload&action=upload',
				acceptedFiles: '.csv,.txt',
				maxFiles: 1,
				maxFilesize: 1,
			}, dropzoneDefaults()),
			new_user: {
				email: '',
				title: '',
				show: false,
			},
			query: null,
			error: null,
			remove_user: null,
		}, data),
		//----------------------------------------------------------------------------------------------------------------------

		methods: {
			/**
			 * Показать форму создания пользователя.
			 */
			addUser: function ()
			{
				this.new_user.show = true;
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Скрыть форму создания пользователя.
			 */
			cancelNewUser: function ()
			{
				this.new_user.show = false;
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Сабмит формы создания нового пользователя.
			 */
			submitNewUser: function ()
			{
				this.error = null;

				this.query = $.ajax({
					url: '/',
					method: 'post',
					data: $.extend({
						page: 'Users',
						action: 'add',
						noview: 1,
					}, this.new_user),
					success: (response) =>
					{
						this.query = null;

						// Пустой ответ означает отсутствие ошибок.
						if (!response)
							document.location.reload();
						else
							this.error = response;
					},
				});
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Сброс пароля.
			 *
			 * @param {object} user
			 */
			resetPassword: function (user)
			{
				this.error = null;

				this.query = $.ajax({
					url: '/',
					method: 'post',
					data: {
						page: 'Users',
						action: 'reset_password',
						noview: 1,
						id: user.id,
					},
					success: (response) =>
					{
						this.query = null;

						if (response)
							this.error = response;
						else
							user.password_hash = true;
					},
				});
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Заморозить или разморозить пользователя.
			 *
			 * @param {object} user
			 * @param {bool} is_active
			 */
			setActive: function (user, is_active)
			{
				this.error = null;

				this.query = $.ajax({
					url: '/',
					method: 'post',
					data: {
						page: 'Users',
						action: 'set_active',
						is_active,
						noview: 1,
						id: user.id,
					},
					success: (response) =>
					{
						this.query = null;

						if (response)
							this.error = response;
						else
							user.is_active = is_active;
					},
				});
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Показ формы подтверждения удаления пользователя.
			 *
			 * @param {object} user
			 */
			removeConfirm: function (user)
			{
				$(this.$refs['modal']).modal();
				this.remove_user = user
			},
			//----------------------------------------------------------------------------------------------------------------------

			/**
			 * Подтверждение удаления пользователя.
			 *
			 * @param {object} user
			 */
			remove: function (user)
			{
				this.error = null;

				this.query = $.ajax({
					url: '/',
					method: 'post',
					data: {
						page: 'Users',
						action: 'remove',
						noview: 1,
						id: user.id,
					},
					success: (response) =>
					{
						this.query = null;

						if (!response)
							document.location.reload();
						else
							this.error = response;
					},
				});
			},
			//----------------------------------------------------------------------------------------------------------------------
		},
		//----------------------------------------------------------------------------------------------------------------------
	});
};
