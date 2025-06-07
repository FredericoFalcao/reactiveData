USE SYS_PRD_BND;
CREATE TABLE `PyPi` (
  `LibName` varchar(255) NOT NULL,
  `AliasName` varchar(255) DEFAULT NULL,
  `LastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`LibName`)
);

