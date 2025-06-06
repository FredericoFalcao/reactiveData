<?php

//
// 1. MariaDB Connection Credentials
//

define("DB_HOST", "localhost");
// The MariaDB User
//
// Create with: (1) CREATE USER 'username'@'localhost' IDENTIFIED BY 'your_password';
//              (2) GRANT ALL PRIVILEGES ON database_name.* TO 'username'@'localhost';
//              (3) FLUSH PRIVILEGES;
define("DB_USER", "SQL_DB_USER");
define("DB_PASS", "SQL_DB_PASS");
define("DB_NAME", "SQL_DB_NAME");



//
// 2. Telegram Reporting
//
$BOT_TOKEN = '1234567890:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // Change to YOUR Telegram Bot Token
$CHAT_IDS  = [
	"NAME_OF_CONTACT_OR_GROUP" => "12345678",
];

