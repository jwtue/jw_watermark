<?php
$EM_CONF[$_EXTKEY] = array(
'title' => 'JW Watermark',
'description' => 'Adds a fluid viewhelper to put watermarks on images',
'category' => 'fe',
'author' => 'Jonas Wolf',
'author_company' => '',
'author_email' => '',
'dependencies' => 'extbase,fluid',
'state' => 'beta',
'clearCacheOnLoad' => '1',
'version' => '0.9.0',
'constraints' => array(
	'depends' => array(
		'typo3' => '8.7.0-10.9.99',
		'php' => '5.4.0-8.0.99',
		'extbase' => '1.0.0-0.0.0',
		'fluid' => '1.0.0-0.0.0',
		)
	)
);