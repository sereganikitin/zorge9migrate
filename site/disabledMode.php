<?php
	/************************************************************
	 * DISABLED ADD/EDIT/DELETE DB — STUB                       |
	 *                                                          |
	 * Оригинал жил на ART3D (feed.art3d.ru/disabledMode/...)   |
	 * и удалённо переключал DISABLED_EDIT_MODE.                |
	 *                                                          |
	 * После миграции на zorge9.infoseledka.ru этот хост не в   |
	 * белом списке ART3D → API возвращал false → редактирование|
	 * было заблокировано для всех ролей.                       |
	 *                                                          |
	 * Заменено на локальную заглушку: всегда return false      |
	 * (= редактирование разрешено). Это убирает сетевую        |
	 * зависимость от стороннего сервера и утечку HEX-секрета   |
	 * на feed.art3d.ru при каждом admin-запросе.               |
	 *                                                          |
	 * Публичный API (класс DisabledMode + метод                |
	 * getDisabledEditMode + константы) сохранён для совмести-  |
	 * мости с потребителями.                                   |
	 ************************************************************/

	if(!defined('DISABLED_CHECK_URL')) define('DISABLED_CHECK_URL', '');
	if(!defined('DISABLED_EDIT_MODE')) define('DISABLED_EDIT_MODE', false);

	class DisabledMode {
		public function siteAvailability(string $host, int $port, ?float $timeout): bool {
			return false;
		}

		public function getDisabledEditMode(): bool {
			return false;
		}
	}
