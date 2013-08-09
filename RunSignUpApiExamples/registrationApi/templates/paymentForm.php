<!doctype html>
<html>
  <head>
		<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet"></link>
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
		<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>
  </head>
  <body>
		<div class="container">
			<h1>Registration</h1>
			
			<p>
				TODO: List registration summary information.
			</p>
			
			<table class="table">
				<tr>
					<th>Description</th>
					<th>Quantity</th>
					<th>Price</th>
				</tr>
				<?php foreach ($resp['cart'] as $cartItem): ?>
					<tr>
						<td>
							<?php echo htmlspecialchars($cartItem['info']); ?>
							<?php if (!empty($cartItem['subitems'])): ?>
								<ul>
									<?php foreach ($cartItem['subitems'] as $subItem): ?>
										<li><?php echo htmlspecialchars($subItem); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td><?php echo number_format($cartItem['quantity']); ?></td>
						<td><?php echo $cartItem['total_cost']; ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			
			<span class="label">Base Amount:</span> <?php echo $resp['base_cost']; ?><br/>
			<?php if (isset($resp['discount_amount']) && $resp['discount_amount'] != '$0.00'): ?>
				<span class="label">Discount:</span> -<?php echo $resp['discount_amount']; ?><br/>
			<?php endif; ?>
			<span class="label">Processing Fee:</span> <?php echo $resp['processing_fee']; ?><br/>
			<span class="label">Total Cost:</span> <b><?php echo $resp['total_cost']; ?></b><br/>
			
			<form method="post">
				<?php if ($resp['total_cost'] !== '$0.00'): ?>
					<h2>Credit Card Info</h2>
					
					<div class="row-fluid">
						<div class="span4">
							<label>First Name</label>
							<input type="text" name="cc_first_name" required="required"/>
						</div>
						<div class="span4">
							<label>Last Name</label>
							<input type="text" name="cc_last_name" required="required"/>
						</div>
					</div>
					
					<div class="row-fluid">
						<div class="span8">
							<label>Address</label>
							<input type="text" class="input-xxlarge" name="cc_address1" required="required"/>
						</div>
					</div>
					<div class="row-fluid">
						<div class="span4">
							<label>City</label>
							<input type="text" name="cc_city" required="required"/>
						</div>
						<div class="span4">
							<label>State</label>
							<select name="cc_state">
								<option value=""></option>
								<option value="AL">Alabama</option>
								<option value="AK">Alaska</option>
								<option value="AZ">Arizona</option>
								<option value="AR">Arkansas</option>
								<option value="CA">California</option>
								<option value="CO">Colorado</option>
								<option value="CT">Connecticut</option>
								<option value="DE">Delaware</option>
								<option value="DC">District Of Columbia</option>
								<option value="FL">Florida</option>
								<option value="GA">Georgia</option>
								<option value="HI">Hawaii</option>
								<option value="ID">Idaho</option>
								<option value="IL">Illinois</option>
								<option value="IN">Indiana</option>
								<option value="IA">Iowa</option>
								<option value="KS">Kansas</option>
								<option value="KY">Kentucky</option>
								<option value="LA">Louisiana</option>
								<option value="ME">Maine</option>
								<option value="MD">Maryland</option>
								<option value="MA">Massachusetts</option>
								<option value="MI">Michigan</option>
								<option value="MN">Minnesota</option>
								<option value="MS">Mississippi</option>
								<option value="MO">Missouri</option>
								<option value="MT">Montana</option>
								<option value="NE">Nebraska</option>
								<option value="NV">Nevada</option>
								<option value="NH">New Hampshire</option>
								<option value="NJ">New Jersey</option>
								<option value="NM">New Mexico</option>
								<option value="NY">New York</option>
								<option value="NC">North Carolina</option>
								<option value="ND">North Dakota</option>
								<option value="OH">Ohio</option>
								<option value="OK">Oklahoma</option>
								<option value="OR">Oregon</option>
								<option value="PA">Pennsylvania</option>
								<option value="RI">Rhode Island</option>
								<option value="SC">South Carolina</option>
								<option value="SD">South Dakota</option>
								<option value="TN">Tennessee</option>
								<option value="TX">Texas</option>
								<option value="UT">Utah</option>
								<option value="VT">Vermont</option>
								<option value="VA">Virginia</option>
								<option value="WA">Washington</option>
								<option value="WV">West Virginia</option>
								<option value="WI">Wisconsin</option>
								<option value="WY">Wyoming</option>
							</select>
						</div>
						<div class="span4">
							<label>Zip Code</label>
							<input type="text" class="input-small" name="cc_zipcode" required="required"/>
						</div>
					</div>
					
					<div class="row-fluid">
						<div class="span4">
							<label>Card Number</label>
							<input type="text" name="cc_num" required="required"/>
						</div>
						
						<div class="span4">
							<label>CVV (Card Security Code)</label>
							<input type="text" class="input-mini" name="cc_cvv" required="required"/>
						</div>
						
						<div class="span4">
							<label>Card Expires (mm/yyyy)</label>
							<input type="text" pattern="[0-1]?[0-9]/[1-2][0-9]{3}" class="input-small" name="cc_expires" required="required"/>
						</div>
					</div>
				<?php endif; ?>
				<div class="text-center" style="margin-top: 25px;">
					<?php foreach ($_POST as $key=>$value): ?>
						<input type="hidden" name="jsonRequest" value="<?php echo htmlspecialchars($jsonRequest); ?>" />
						<input type="hidden" name="totalCost" value="<?php echo $resp['total_cost']; ?>" />
					<?php endforeach; ?>
					
					<input type="submit" class="btn btn-primary btn-large" value="Complete Registration" />
				</div>
			</form>
		</div>
	</body>
</html>