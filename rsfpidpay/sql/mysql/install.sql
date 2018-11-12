INSERT IGNORE INTO `#__rsform_config` (`SettingName`, `SettingValue`) VALUES
('idpay.api', ''),
('idpay.success_massage', ''),
('idpay.failed_massage', ''),
('idpay.sandbox', '');

INSERT IGNORE INTO `#__rsform_component_types` (`ComponentTypeId`, `ComponentTypeName`) VALUES (3543, 'idpay');

DELETE FROM #__rsform_component_type_fields WHERE ComponentTypeId = 3543;
INSERT IGNORE INTO `#__rsform_component_type_fields` (`ComponentTypeId`, `FieldName`, `FieldType`, `FieldValues`, `Ordering`) VALUES
(3543, 'NAME', 'textbox', '', 0),
(3543, 'LABEL', 'textbox', '', 1),
(3543, 'COMPONENTTYPE', 'hidden', '3543', 2),
(3543, 'LAYOUTHIDDEN', 'hiddenparam', 'YES', 7);