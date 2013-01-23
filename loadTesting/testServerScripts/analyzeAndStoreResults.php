<?php

ini_set('display_errors', 0);
ini_set('memory_limit', '2G');

define('S3_BUCKET', '<Bucket Name>');

// JSON response
$response = array();

// Get options from args
$longopts  = array(
	's3-filename:'
);
$options = getopt('', $longopts);

// Check if we are pushing to S3
if (!empty($options['s3-filename']))
{
	// Tar results
	$cmd = 'tar czvhf results.tgz logs output cookies';
	exec($cmd, $output, $rtn);
	if ($rtn != 0)
		$response['error'][] = 'Failed to upload results to S3.';
	else
	{
		// Push to S3
		require_once('amazonSDK/sdk.class.php');
		$s3 = new AmazonS3();
		$s3Resp = $s3->create_object(S3_BUCKET, $options['s3-filename'], array(
			'fileUpload' => 'results.tgz',
			'storage' => AmazonS3::STORAGE_REDUCED
		));
		if (!$s3Resp->isOk())
			$response['error'][] = 'Failed to upload results to S3.';
	}
}

# Analyze

// Process each log file
$response = array();

// Process log and output files to generate stats

// Output JSON
echo json_encode($response);

?>
