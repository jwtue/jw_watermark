<?php
$EM_CONF[$_EXTKEY] = array(
'title' => 'JW Watermark',
'description' => 'Adds a fluid viewhelper to put watermarks on images',
'category' => 'fe',
'author' => 'Jonas Wolf',
'author_company' => '',
'author_email' => '',
'dependencies' => 'extbase,fluid',
'state' => 'stable',
'clearCacheOnLoad' => '1',
'version' => '12.0.0',
'constraints' => array(
	'depends' => array(
		'typo3' => '12.0.0-12.9.99',
		'php' => '8.0.0-8.3.99',
		'extbase' => '1.0.0-0.0.0',
		'fluid' => '1.0.0-0.0.0',
		)
	)
);