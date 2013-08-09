<?php if (!empty($membershipSetting['user_notice'])): ?>
	<div class="well well-small"><?php echo nl2br(htmlspecialchars($membershipSetting['user_notice'])); ?></div>
<?php endif; ?>

<!-- Radio Buttons -->
<label class="radio inline">
	<input type="radio" name="membershipSetting[<?php echo $membershipSetting['membership_setting_id']; ?>][member]" value="T" required="required" />
	<?php if ($membershipSetting['yes_option_text']): ?>
		<?php echo htmlspecialchars($membershipSetting['yes_option_text']); ?>
	<?php else: ?>
		Member
	<?php endif; ?>
</label>
<label class="radio inline">
	<input type="radio" name="membershipSetting[<?php echo $membershipSetting['membership_setting_id']; ?>][member]" value="F" required="required" />
	<?php if ($membershipSetting['no_option_text']): ?>
		<?php echo htmlspecialchars($membershipSetting['no_option_text']); ?>
	<?php else: ?>
		Not a Member
	<?php endif; ?>
</label>

<!-- Addl Field -->
<div class="row-fluid" style="margin-top: 10px;">
	<?php if (!empty($membershipSetting['membership_setting_addl_field'])): ?>
		<div class="span6">
			<label><?php echo htmlspecialchars($membershipSetting['membership_setting_addl_field']['field_text']); ?></label>
			<input type="text" name="membershipSetting[<?php echo $membershipSetting['membership_setting_id']; ?>][addl_field]" />
		</div>
	<?php endif; ?>
	
	<?php if ($membershipSetting['usat_specific'] == 'T' && $membershipSetting['usat_one_day_license_required'] == 'T'): ?>
		<label>USAT One Day Membership</label>
		<input type="text" name="membershipSetting[<?php echo $membershipSetting['membership_setting_id']; ?>][non_member_addl_field]" />
	<?php endif; ?>
</div>