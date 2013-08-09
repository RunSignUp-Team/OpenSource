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
		
		// Set up user id selector
		$(function() {
			var users = <?php echo json_encode($users); ?>;
			$("select[name='user_id']").on("change", function() {
				var userId = $(this).val();
				var passwordInput = $("input[name='password']");
				passwordInput.prop('disabled', false);
				if (userId && userId in users)
				{
					var user = users[userId];
					var inputs = $(":input");
					inputs.each(function() {
						var field = this.name;
						if (field == 'address1')
							$(this).val(user.address.street);
						else if (field == 'gender')
							this.checked = user.gender == this.value;
						else if (field == 'dob')
						{
							var regex = /^([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})$/g;
							var match = regex.exec(user.dob);
							if (match)
							{
								if (this.type === "date")
								{
									if (match[1].length == 1)
										match[1] = "0" + match[1];
									if (match[2].length == 1)
										match[2] = "0" + match[2];
									$(this).val(match[3]+"-"+match[1]+"-"+match[2]);
								}
								else
									$(this).val(user.dob);
							}
						}
						else if (field in user.address)
							$(this).val(user.address[field]);
						else if (field in user)
							$(this).val(user[field]);
					});
					passwordInput.prop('disabled', true);
				}
			});
		});
		
		//]]></script>
  </head>
  <body>
		<div class="container">
			<h1><?php echo htmlspecialchars($resp['race']['name']); ?> Registration</h1>
			
			<p>
				This is a sample registration form for this race.
				You should NOT use this to build the form dynamically.
				This is intended to serve as an example to create your custom registration form.
				Once you have created your form, do NOT edit race information as this could change field ids and cause your form to stop working.
			</p>
			
			<form method="post">
				<h2 class="pull-left">Registrant Info</h2>
				<?php if (!$this->userId): ?>
					<a class="pull-right btn btn-large" href="?action=signin">Sign In</a>
				<?php else: ?>
					<a class="pull-right btn btn-large" href="?action=signout">Sign Out</a>
				<?php endif; ?>
				
				<br style="clear: both;" />
				
				<?php if (count($users) > 0): ?>
					<div class="row-fluid">
						<div class="span4">
							<label>Existing Accounts</label>
							<select name="user_id">
								<option value="">Create New User</option>
								<?php foreach ($users as $user): ?>
									<option value="<?php echo $user['user_id']; ?>"><?php echo htmlspecialchars($user['first_name']); ?> <?php echo htmlspecialchars($user['last_name']); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				<?php endif; ?>
				
				<div class="row-fluid">
					<div class="span4">
						<label>First Name</label>
						<input type="text" name="first_name" required="required"/>
					</div>
					<div class="span4">
						<label>Last Name</label>
						<input type="text" name="last_name" required="required"/>
					</div>
				</div>
				
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
				
				<div class="row-fluid">
					<div class="span8">
						<label>Address</label>
						<input type="text" class="input-xxlarge" name="address1" required="required"/>
					</div>
				</div>
				<div class="row-fluid">
					<div class="span4">
						<label>City</label>
						<input type="text" name="city" required="required"/>
					</div>
					<div class="span4">
						<label>State</label>
						<select name="state">
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
						<input type="text" class="input-small" name="zipcode" required="required"/>
					</div>
				</div>
				
				<div class="row-fluid">
					<div class="span4">
						<label>Phone</label>
						<input type="tel" name="phone" required="required"/>
					</div>
					<div class="span4">
						<label>Date of Birth</label>
						<input type="date" name="dob" required="required"/>
						<div class="dateFormatNote help-block">Format: yyyy-mm-dd</div>
					</div>
					<div class="span4">
						<label>Gender</label>
						<label class="radio inline">
							<input type="radio" name="gender" value="M" required="required"/>
							Male
						</label>
						<label class="radio inline">
							<input type="radio" name="gender" value="F" required="required"/>
							Female
						</label>
					</div>
				</div>
				
				<h2>Select an Event</h2>
				<div>
					<ul class="unstyled" id="event-selector">
						<?php foreach ($resp['race']['events'] as $event): ?>
							<li>
								<label class="checkbox">
									<input type="checkbox" name="event[<?php echo $event['event_id']; ?>][selected]" value="T" data-event-id="<?php echo $event['event_id']; ?>"/>
									<?php echo htmlspecialchars($event['name']); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
					
					<!-- Event Specifics -->
					<?php foreach ($resp['race']['events'] as $event): ?>
						<div class="event-<?php echo $event['event_id']; ?>" style="display: none;">
							<?php if (!empty($event['giveaway_options'])): ?>
								<h3><?php echo $event['giveaway']; ?></h3>
								<select name="event[<?php echo $event['event_id']; ?>][giveaway]">
									<option value=""></option>
									<?php foreach ($event['giveaway_options'] as $option): ?>
										<option value="<?php echo $option['giveaway_option_id']; ?>"><?php echo htmlspecialchars($option['giveaway_option_text']); ?> <?php echo $option['additional_cost'] !== '$0.00' ? '(' . $option['additional_cost'] . ')' : ''; ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
							
							
							<!-- Membership settings -->
							<?php if (!empty($event['membership_settings'])): ?>
								<?php foreach ($event['membership_settings'] as $membershipSetting): ?>
									<h3><?php echo htmlspecialchars($membershipSetting['membership_setting_name']); ?></h3>
									
									<?php include('templates/regForm-membership.php'); ?>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				
				<!-- Questions -->
				<?php if (!empty($resp['race']['questions'])): ?>
					<h2>Questions</h2>
					<?php foreach ($resp['race']['questions'] as $question): ?>
						<div>
							<label><?php echo htmlspecialchars($question['question_text']); ?></label>
							<?php
								$questionInputName = $question['individual'] == 'T' ? 'individualQuestionResponse' : 'questionResponse';
								$questionInputName .= '[' . $question['question_id'] . ']';
							?>
							<?php if ($question['question_type_code'] == 'F'): ?>
								<input type="text" name="<?php echo $questionInputName; ?>" <?php if ($question['required'] == 'T'): ?>required="required"<?php endif; ?>/>
							<?php elseif ($question['question_type_code'] == 'B'): ?>
								<label class="radio">
									<input type="radio" name="<?php echo $questionInputName; ?>" value="T" <?php if ($question['required'] == 'T'): ?>required="required"<?php endif; ?>/>
									Yes
								</label>
								<label class="radio">
									<input type="radio" name="<?php echo $questionInputName; ?>" value="F" <?php if ($question['required'] == 'T'): ?>required="required"<?php endif; ?>/>
									No
								</label>
							<?php elseif ($question['question_type_code'] == 'R'): ?>
								<?php foreach ($question['responses'] as $response): ?>
									<label class="radio">
										<input type="radio" name="<?php echo $questionInputName; ?>" value="<?php echo htmlspecialchars($response['response_id']); ?>" <?php if ($question['required'] == 'T'): ?>required="required"<?php endif; ?>/>
										<?php echo htmlspecialchars($response['response']); ?>
									</label>
								<?php endforeach; ?>
							<?php elseif ($question['question_type_code'] == 'S'): ?>
								<select name="<?php echo $questionInputName; ?>" <?php if ($question['required'] == 'T'): ?>required="required"<?php endif; ?>>
									<option value=""></option>
									<?php foreach ($question['responses'] as $response): ?>
										<option value="<?php echo htmlspecialchars($response['response_id']); ?>">
											<?php echo htmlspecialchars($response['response']); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php elseif ($question['question_type_code'] == 'C'): ?>
								<?php foreach ($question['responses'] as $response): ?>
									<label class="checkbox">
										<input type="checkbox" name="<?php echo $questionInputName; ?>[]" value="<?php echo htmlspecialchars($response['response_id']); ?>"/>
										<?php echo htmlspecialchars($response['response']); ?>
									</label>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
				
				<!-- Add-ons -->
				<?php if (!empty($resp['race']['registration_addons'])): ?>
					<h2>Registration Add-Ons</h2>
					<?php foreach ($resp['race']['registration_addons'] as $addon): ?>
						<?php
							// Build add-on input name prefix and min.max quantities
							$addonMinQuantity = (int)$addon['min_quantity_per_transaction'];
							$addonMaxQuantity = $addon['max_quantity_per_transaction'];
							$addonFieldNamePrefix = 'overallAddon[' . $addon['addon_id'] . ']';
							if ($addon['per_registrant_event'] == 'T')
							{
								$addonMinQuantity = max($addonMinQuantity, (int)$addon['min_quantity_per_registrant_event']);
								if ($addon['max_quantity_per_registrant_event'] !== null)
									$addonMaxQuantity = $addonMaxQuantity === null ? $addon['max_quantity_per_registrant_event'] : min($addonMaxQuantity, $addon['max_quantity_per_registrant_event']);
								$addonFieldNamePrefix = 'registrantEventAddon[' . $addon['addon_id'] . ']';
							}
							else if ($addon['per_registrant'] == 'T')
							{
								$addonMinQuantity = max($addonMinQuantity, (int)$addon['min_quantity_per_registrant']);
								if ($addon['max_quantity_per_registrant'] !== null)
									$addonMaxQuantity = $addonMaxQuantity === null ? $addon['max_quantity_per_registrant'] : min($addonMaxQuantity, $addon['max_quantity_per_registrant']);
								$addonFieldNamePrefix = 'registrantAddon[' . $addon['addon_id'] . ']';
							}
						?>
						<div>
							<h3><?php echo htmlspecialchars($addon['addon_name']); ?> (<?php echo htmlspecialchars($addon['addon_price']); ?>)</h3>
							<?php if (!empty($addon['addon_desc'])): ?>
								<div class="well well-small"><?php echo nl2br(htmlspecialchars($addon['addon_desc'])); ?></div>
							<?php endif; ?>
							
							<!-- Options version -->
							<?php if (!empty($addon['addon_options'])): ?>
								<?php foreach ($addon['addon_options'] as $option): ?>
									<label><?php echo htmlspecialchars($option['option_text']); ?></label>
									<input type="number" class="input-small" min="<?php echo $addonMinQuantity; ?>" <?php if ($addonMaxQuantity !== null): ?>max="<?php echo $addonMaxQuantity; ?>"<?php endif; ?> name="<?php echo $addonFieldNamePrefix; ?>[optionQuantity][<?php echo $option['addon_option_id']; ?>]" />
								<?php endforeach; ?>
							<!-- No options version -->
							<?php else: ?>
								<label>How many?</label>
								<input type="number" class="input-small" min="<?php echo $addonMinQuantity; ?>" <?php if ($addonMaxQuantity !== null): ?>max="<?php echo $addonMaxQuantity; ?>"<?php endif; ?> name="<?php echo $addonFieldNamePrefix; ?>[quantity]" />
							<?php endif; ?>
							
							<!-- Custom Fields -->
							<?php if (!empty($addon['addon_custom_fields'])): ?>
								<?php foreach ($addon['addon_custom_fields'] as $customField): ?>
									<label><?php echo htmlspecialchars($customField['custom_field_name']); ?></label>
									<input type="text" name="<?php echo $addonFieldNamePrefix; ?>[customField][<?php echo $customField['custom_field_id']; ?>]" />
									<span class="help-block"><?php echo htmlspecialchars($customField['custom_field_desc']); ?></span>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
				
				<!-- Membership settings -->
				<?php if (!empty($resp['race']['membership_settings'])): ?>
					<?php foreach ($resp['race']['membership_settings'] as $membershipSetting): ?>
						<h2><?php echo htmlspecialchars($membershipSetting['membership_setting_name']); ?></h2>
						
						<?php include('templates/regForm-membership.php'); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				
				<div class="row-fluid">
					<div class="span4">
						<h2>Coupon</h2>
						<input type="text" name="coupon" />
					</div>
				</div>
				
				<h2>Waiver</h2>
				<div>
					<textarea readonly="readonly" class="input-block-level" style="height: 150px;"><?php echo htmlspecialchars($resp['race']['waiver']); ?></textarea>
					
					<label class="checkbox">
						<input type="checkbox" name="acceptWaiver" value="T" required="required" />
						By checking this box, I agree to the waiver and that I am 18 or older, or that I have the authority to register these participants and agree to the waiver for them.
					</label>
				</div>
				
				<div class="text-center" style="margin-top: 25px;">
					<input type="submit" class="btn btn-primary btn-large" value="Continue" />
				</div>
			</form>
		</div>
		
		<script type="text/javascript">//<![CDATA[
		
		// Update HTML5 date inputs
		$(function() {
			var input = document.createElement("input");
			input.setAttribute("type", "date");
			if (input.type === "date")
			{
				$(".dateFormatNote").remove();
			}
		});
		//]]></script>
	
	</body>
</html>