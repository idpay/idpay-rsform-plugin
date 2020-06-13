INSERT IGNORE INTO `#__rsform_config` (`SettingName`, `SettingValue`) VALUES
('idpay.api', ''),
('idpay.success_massage', ''),
('idpay.failed_massage', ''),
('idpay.sandbox', ''),
('idpay.currency', '');

INSERT IGNORE INTO `#__rsform_component_types` (`ComponentTypeId`, `ComponentTypeName`) VALUES (3543, 'idpay');

DELETE FROM `#__rsform_component_type_fields` WHERE ComponentTypeId = 3543;

INSERT IGNORE INTO `#__rsform_component_type_fields` (`ComponentTypeId`, `FieldName`, `FieldType`, `FieldValues`,`Properties`,`Ordering`) VALUES
(3543, 'NAME', 'textbox','','', 0),
(3543, 'LABEL', 'textbox','','', 1),
(3543, 'TOTAL', 'select', 'YES\r\nNO', '{"case":{"YES":{"show":[],"hide":["FIELDNAME"]},"NO":{"show":["FIELDNAME"],"hide":[]}}}',3 ),
(3543, 'FIELDNAME', 'select','//<code>\r\n $a="Select the desired field"; foreach (RSFormProHelper::getComponents($_GET["formId"]) as $item) { if ($item->ComponentTypeId == 21 or $item->ComponentTypeId == 22 or $item->ComponentTypeId == 28 or $item->ComponentTypeId == 23){  $a= $a . "\r\n" . $item->name; } } return $a;   \r\n//</code>','', 4),
(3543, 'COMPONENTTYPE', 'hidden', '3543','', 5),
(3543, 'LAYOUTHIDDEN', 'hiddenparam', 'YES','', 7);
