#
# Table structure for table 'tx_formtodatabase_domain_model_formresult'
#
CREATE TABLE tx_formtodatabase_domain_model_formresult (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) DEFAULT '0' NOT NULL,
	crdate int(11) DEFAULT '0' NOT NULL,
	cruser_id int(11) DEFAULT '0' NOT NULL,
	deleted tinyint(4) DEFAULT '0' NOT NULL,

	form_persistence_identifier varchar(255) DEFAULT '' NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
    form_plugin_uid int(11) NOT NULL,

    result mediumtext,

	PRIMARY KEY (uid),
	KEY parent (pid)
);
