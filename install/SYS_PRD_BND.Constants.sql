USE SYS_PRD_BND;
CREATE TABLE `Constants` (
  `Name` varchar(255) NOT NULL,
  `Type` enum('String','Int','Double','Json') NOT NULL,
  `Value` text NOT NULL,
  `LastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Name`)
);