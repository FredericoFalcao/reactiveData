USE SYS_PRD_BND;
CREATE TABLE `Composer` (
  `VendorName` varchar(255) NOT NULL,
  `PackageName` varchar(255) NOT NULL,
  `VersionOrBranch` varchar(25) NOT NULL,
  `RepositoryUrl` varchar(255) DEFAULT NULL,
  `RepositoryType` enum('vcs') NOT NULL DEFAULT 'vcs',
  `LastUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`VendorName`,`PackageName`)
);
