УСТАНОВКА (OSPanel)
1. Скопируйте всё содержимое папки в корень сайта: OSPanel/domains/<ваш-сайт>/
2. Откройте phpMyAdmin -> вкладка «Импорт» -> выберите database.sql -> «Вперёд».
   Создастся база odun_tur, таблицы leads / admins / login_attempts и администратор.
3. Если у MySQL свой пароль/другое имя базы — поправьте inc/config.php.
4. Сайт: http://<ваш-сайт>/    Админка: http://<ваш-сайт>/admin/
   Логин: admin    Пароль: oduntour2026
5. После запуска удалите database.sql и README.txt с боевого сервера.
Смена пароля: в phpMyAdmin выполните UPDATE admins SET password_hash='<новый bcrypt-хеш>' WHERE login='admin';
