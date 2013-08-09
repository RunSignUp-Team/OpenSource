<!doctype html>
<html>
  <head>
		<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet"></link>
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
		<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>
  </head>
  <body>
		<div class="container">
			<h1>Registration Confirmation</h1>
			
			<?php foreach ($resp['registrations'] as $registration): ?>
				<h3>Registration #<?php echo $registration['registration_id']; ?></h3>
				<span class="label">Bib Number:</span> <?php echo $registration['bib_num']; ?><br/>
				<span class="label">Confirmation Code:</span> <?php echo $registration['confirmation_code']; ?><br/>
			<?php endforeach; ?>
			<form method="post">
				<div class="text-center" style="margin-top: 25px;">
					<input type="hidden" name="refund" value="" />
					<input type="hidden" name="primaryRegistrationId" value="<?php echo $resp['primary_registration_id']; ?>" />
					<input type="hidden" name="primaryConfirmationCode" value="<?php echo $resp['primary_confirmation_code']; ?>" />
					<input type="submit" class="btn btn-primary btn-large" value="Refund" />
				</div>
			</form>
		</div>
	</body>
</html>