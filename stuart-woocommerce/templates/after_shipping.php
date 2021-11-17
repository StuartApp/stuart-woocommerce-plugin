<?php

/**
  *
  *	Copy this file in wp-content/themes/{mytheme}/plugins/stuart/templates/after_shipping.php
  * to override its content without fear of updates.
  * Use $delivery to get access to Stuart Shipping Method class
  *
  */

?>
<?php if (isset($time_list) && !empty($time_list)): ?>

	<style>
		.switch {
		  position: relative;
		  display: inline-block !important;
		  width: 60px;
		  height: 34px;
		}

		.switch input { 
		  opacity: 0;
		  width: 0;
		  height: 0;
		}

		.slider {
		  position: absolute;
		  cursor: pointer;
		  top: 0;
		  left: 0;
		  right: 0;
		  bottom: 0;
		  background-color: #ccc;
		  -webkit-transition: .4s;
		  transition: .4s;
		}

		.slider:before {
		  position: absolute;
		  content: "";
		  height: 26px;
		  width: 26px;
		  left: 4px;
		  bottom: 4px;
		  background-color: white;
		  -webkit-transition: .4s;
		  transition: .4s;
		}

		input:checked + .slider {
		  background-color: #2196F3;
		}

		input:focus + .slider {
		  box-shadow: 0 0 1px #2196F3;
		}

		input:checked + .slider:before {
		  -webkit-transform: translateX(26px);
		  -ms-transform: translateX(26px);
		  transform: translateX(26px);
		}

		.slider.round {
		  border-radius: 34px;
		}

		.slider.round:before {
		  border-radius: 50%;
		}
	</style>
	
	<div class="stuart_schedule" style="margin: 5px 0px;">

		<div class="stuart_logo"><img src="<?php echo esc_url($stuart_logo); ?>" width=100 alt="Stuart" /></div>
		
		<?php if (!empty($time_list)): ?>
		
			<div style="margin: 5px;" class="subtitle stuart_delivery_title"><?php esc_html_e('When do you want to receive your delivery?', 'stuart-delivery'); ?></div>
		 	
			<label onclick="markDeliverNow()" class="switch">
  				<input id="deliver-now-checkbox" type="checkbox" checked>
  				<span class="slider round"></span>
			</label>

			<div id="stuart_schedule_now_title" style="display:inline;vertical-align: -webkit-baseline-middle;"><?php esc_html_e('As soon as possible', 'stuart-delivery'); ?></div>
			<div id="stuart_schedule_future" style="display:none;vertical-align: -webkit-baseline-middle;"><?php esc_html_e('At a specific time', 'stuart-delivery'); ?></div>

			<div id="stuart_delivery_wrapper" style="display:none;margin: 5px 0px;">
				<div style="display: inline;" class="stuart_delivery_date">
					<select onchange="setTimeSlots(event)" name="stuart_date" id="stuart_date" data-server-time="<?php echo esc_html($server_time); ?>">
						<?php foreach ($time_list as $i=>$time) {
    echo '<option '.($i === 0 ? 'selected' : '').' data-time-day="'.esc_html($delivery->formatToDate('Y/m/d', $delivery->getTime($time['day']))).'" data-time-before="'.esc_html($time['before']).'" data-time-after="'.esc_html($time['after']).'"  data-start-pause="'.esc_html($time['pause_start']).'" data-end-pause="'.esc_html($time['pause_end']).'" value="'.esc_html($time['day']).'">'.esc_html($delivery->formatToDate('d/m', $delivery->getTime($time['day']))).'</option>';
} ?>
					</select>
				</div>
				<div style="display: inline;" class="stuart_delivery_at">
	  				<?php esc_html_e('at', 'stuart-delivery'); ?>
	  			</div>
				<div style="display: inline;" class="stuart_delivery_time">
	  				<select onchange="setPickUpTime(event)" name="stuart_time" id="stuart_time"></select>
				</div>
			</div>
				
			<div class="clearfix"></div>
		
			<div id="stuart_result_ajax">
			</div>

			<?php echo '<script>window.delay = '.$delay.'</script>' ?>

			<script>

				function markDeliverNow(){
					const checkBox = document.getElementById("deliver-now-checkbox");
  					const deliverNowText = document.getElementById("stuart_schedule_now_title");
					const deliverLaterText = document.getElementById("stuart_schedule_future");
					const timeSlots = document.getElementById("stuart_delivery_wrapper");
  					if (checkBox.checked === true){
  						deliverNowText.style.display = "inline";
						deliverLaterText.style.display = "none";
						timeSlots.style.display = "none";
						const timeSelection = document.querySelector('#stuart_time');
						timeSelection.innerHTML = '';
  					} else {
						deliverNowText.style.display = "none";
						deliverLaterText.style.display = "inline";
						timeSlots.style.display = "block";
						setTimeSlots();
						setPickUpTime();
  					}
				}


				function setTimeSlots(e){
					const selectedOption = e ? e.target.options[e.target.selectedIndex].dataset : document.getElementById('stuart_date').options[0].dataset; 
    				const startPoint = new Date(`${selectedOption.timeDay} ${selectedOption.timeAfter}`).getTime();
    				const endingPoint = new Date(`${selectedOption.timeDay} ${selectedOption.timeBefore}`).getTime();
    				const startPause = selectedOption.startPause !== "00:00" ? new Date(`${selectedOption.timeDay} ${selectedOption.startPause}`).getTime() : null;
    				const endingPause = selectedOption.endPause !== "00:00" ? new Date(`${selectedOption.timeDay} ${selectedOption.endPause}`).getTime() : null;
					const delayMs = delay * 60000;
					const now = new Date().getTime() + delayMs;
    				const timeSlots = [];
					for (let i=(now > startPoint && new Date(selectedOption.timeDay).getDate() === new Date().getDate() ? now : startPoint ); i+delayMs < endingPoint; i += delayMs){
    				    if (startPause < i && i < endingPause){
							continue;
						} else {
							timeSlots.push(new Date(i));
						}
    				}
					function addZero(i) {
  						if (i < 10) {i = "0" + i}
  						return i;
					}
					const timeSelection = document.querySelector('#stuart_time');
					timeSelection.innerHTML = '';
    				timeSlots.forEach((timeSlot) => {
						timeSelection.append(new Option(`${addZero(timeSlot.getHours())}:${addZero(timeSlot.getMinutes())}`, Math.round(timeSlot.getTime()/1000)));
					}) 
				}

				function setPickUpTime(e){
					const selectedOption = e ? e.target.options[e.target.selectedIndex].value : document.getElementById('stuart_time').options[0].value; 
					document.querySelector('#stuart_pickup_time').value = selectedOption;
				}

				markDeliverNow();
				document.querySelector('.stuart_schedule').closest('li').prepend(document.querySelector('.stuart_schedule'));

			</script>

			<input type="hidden" name="stuart_pickup_hour" id="stuart_pickup_hour" value="<?php echo $current_job_time; ?>">
		
		<?php endif; ?>
		
	</div>

<?php endif; ?>