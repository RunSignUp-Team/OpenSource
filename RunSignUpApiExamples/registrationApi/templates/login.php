<!doctype html>
<html>
  <head>
		<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet"></link>
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
		<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>
    <script type="text/javascript">//<![CDATA[

		// Set up event selectors
		$(function() {
			$("#event-selector").find("input").on("change", function() {
				$(".event-"+$(this).data('event-id')).toggle(this.checked).find(":input").prop('disabled', !this.checked);
				
			}).each(function() { $(this).triggerHandler("change"); });
		});
		
		//]]></script>
  </head>
  <body>
		<div class="container">
			<h1>Sign In</h1>
			
			<form method="post">
				<div class="row-fluid">
					<div class="span4">
						<label>E-mail Address</label>
						<input type="email" name="email" required="required"/>
					</div>
					<div class="span4">
						<label>Password</label>
						<input type="password" name="password" required="required"/>
						<span class="help-block">This will create a RunSignUp account for your E-mail address.</span>
					</div>
				</div>
				
				<div class="text-center" style="margin-top: 25px;">
					<input type="submit" class="btn btn-primary btn-large" value="Login" />
				</div>
			</form>
		</div>
	
	</body>
</html>