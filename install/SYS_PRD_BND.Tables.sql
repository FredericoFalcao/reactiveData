USE SYS_PRD_BND;
CREATE TABLE `Tables` (
  `Name` varchar(255) NOT NULL,
  `onUpdate_phpCode` text DEFAULT NULL,
  `onUpdate_pyCode` text DEFAULT NULL,
  `onUpdate_jsCode` text DEFAULT NULL,
  `LastError` text DEFAULT NULL,
  `LastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Name`)
);
