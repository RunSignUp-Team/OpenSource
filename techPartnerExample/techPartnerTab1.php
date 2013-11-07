<!DOCTYPE html>
<html>
	<?php
		// RunSignUp Domain name
		$runSignUpBaseUrl = 'https://runsignup.com';
	?>
	<head>
		<title>Technology Partner</title>
		
		<!-- Bootstrap -->
		<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.8.0/jquery.min.js"></script>
		<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>	
	</head>
	<body style="background-color: transparent;">
		<div class="container">
			<h1>Example Custom Tab</h1>
			
			<p>Here are the parameters from <code>$_GET</code></p>
			<pre><?php echo htmlspecialchars(print_r($_GET, 1)); ?></pre>
			
		</div>
		
		<script type="text/javascript">//<![CDATA[
		
		// Send resize messages
		if (window.JSON && window.postMessage)
		{
			$(function() {
				var lastHeight = 0;
				
				var func = (function() {
					var height = $(document.body).height();
					if (height != lastHeight)
					{
						lastHeight = height;
						var msg = {
							'rsuIframeIndex': <?php echo isset($_GET['rsuIframeIndex']) ? json_encode($_GET['rsuIframeIndex']) : 'null'; ?>,
							'action': 'resize',
							'height': height
						};
						parent.postMessage(JSON.stringify(msg), <?php echo json_encode($runSignUpBaseUrl); ?>);
					}
				});
				
				// Initial resize
				func();
				
				// Resize every 250ms
				setInterval(func, 250);
			});
		}
		
		//]]></script>
	</body>
</html>
