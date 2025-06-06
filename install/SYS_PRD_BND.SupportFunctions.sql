USE SYS_PRD_BND;
CREATE TABLE `SupportFunctions` (
  `Name` varchar(255) NOT NULL,
  `InputArgs_json` varchar(255) DEFAULT NULL,
  `PhpCode` text DEFAULT NULL,
  `PythonCode` text DEFAULT NULL,
  `JavascriptCode` text DEFAULT NULL,
  `LastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Name`)
);