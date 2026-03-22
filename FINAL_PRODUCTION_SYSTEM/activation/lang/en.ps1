# KeyGate - English Language File
# Language: English (en)

@{
    # Banner
    'banner'                    = '🔐 KeyGate (Database Edition)'
    'banner_warning'            = '⚠️ Please do NOT close this window manually. It will close automatically.'

    # Authentication
    'prompt_tech_id'            = 'Technician ID'
    'prompt_password'           = 'Password'
    'auth_progress'             = '🔐 Authenticating...'
    'auth_success'              = '✅ Welcome, {0}'
    'auth_failed_attempt'       = '⚠️ Authentication failed. Attempt {0} of {1}'
    'auth_locked'               = '❌ Account is locked. Please contact administrator.'
    'auth_max_attempts'         = '❌ Authentication failed after {0} attempts. Access denied.'
    'auth_failed_continue'      = '❌ Authentication failed. Cannot continue.'
    'tech_id_invalid'           = '⚠️ Technician ID must be alphanumeric, max 20 characters'

    # Password Change
    'pwd_change_required'       = '🔄 Password change required'
    'pwd_change_success'        = '✅ Password changed successfully. You may now proceed with activation.'
    'pwd_change_failed'         = '❌ Password change failed. Cannot continue.'
    'pwd_change_error'          = '❌ Password change failed: {0}'
    'pwd_try_again'             = 'Please try again...'
    'pwd_requirements'          = 'Password Requirements:'
    'pwd_req_length'            = '- At least 8 characters long'
    'pwd_req_complexity'        = '- Must contain uppercase, lowercase, and numbers'
    'pwd_req_different'         = '- Must be different from current password'
    'pwd_new'                   = 'Enter new password'
    'pwd_confirm'               = 'Confirm new password'
    'pwd_mismatch'              = '❌ Passwords do not match. Please try again.'

    # Order Number
    'prompt_order'              = 'Enter the last 5 characters of the order number'
    'order_invalid'             = '⚠️ Order number must be exactly 5 alphanumeric characters'
    'select_order_type'         = 'Select order type'
    'prompt_6_digits'           = 'Enter order number (6 digits)'
    'order_6_digits_invalid'    = '⚠️ Order number must be exactly 6 digits'

    # API Errors
    'api_failed'                = 'API call failed'
    'api_http_error'            = 'API call failed (HTTP {0})'
    'api_invalid_credentials'   = ' - Invalid credentials'
    'api_account_locked'        = ' - Account locked'

    # Time Sync
    'time_sync'                 = '🕐 Synchronizing system time...'
    'time_sync_success'         = '✓ System time synchronized successfully'
    'time_sync_warning'         = '⚠️ Time sync completed with warnings'
    'time_sync_error'           = '⚠️ Time sync encountered an issue: {0}'

    # Key Installation
    'key_installing'            = '🔑 Installing product key...'
    'key_install_code'          = '⚠️ Key installation returned code: {0}'
    'key_install_success'       = '✓ Product key installed'
    'key_install_error'         = '❌ Error installing key: {0}'

    # Activation
    'activation_progress'       = '🌐 Activating Windows...'
    'activation_code'           = '⚠️ Activation returned code: {0}'
    'activation_command_done'   = '✓ Activation command completed'
    'activation_error'          = '❌ Error during activation: {0}'
    'activation_waiting'        = '⏳ Waiting {0} seconds...'
    'activation_checking'       = '⏳ Checking in {0}... '
    'activation_confirmed'      = '✓ Windows activation confirmed!'
    'activation_check_failed'   = '⚠️ Status check failed, retrying...'
    'activation_verify_failed'  = '✗ Activation verification failed'
    'activation_still_waiting'  = '⏳ Still waiting... ({0}s remaining) '
    'activation_retry_wait'     = '⏳ Waiting 5 seconds before retry...'

    # Key Cleanup
    'key_cleanup'               = '🔑 Cleaning up existing key...'
    'key_cleanup_next'          = '🔑 Cleaning up failed key...'

    # Activation Loop
    'attempt_header'            = '--- Attempt #{0} ---'
    'attempt_using_key'         = 'Using key: {0}...'
    'attempt_retry'             = 'Retry with same key? (Y/N)'
    'attempt_max_reached'       = '⚠️ Maximum retry attempts reached for this key.'
    'attempt_report_failed'     = '❌ Failed to report result to server'

    # Key Request
    'key_getting'               = '🔍 Getting activation key... (Key attempt #{0})'
    'key_get_failed'            = '❌ Failed to get activation key from server'
    'key_error'                 = 'Error: {0}'
    'key_invalid_response'      = '❌ Invalid response from server (missing required fields)'
    'key_retrieved'             = '✅ Key retrieved: {0}'
    'key_previous_failures'     = 'ℹ️ This key has {0} previous failure(s)'
    'key_trying_next'           = '🔄 Trying next available key...'
    'key_getting_next'          = '🔄 Getting next available key...'

    # Hardware Collection
    'hw_collecting'             = '📋 Collecting hardware information...'
    'hw_motherboard'            = '  • Motherboard...'
    'hw_chassis'                = '  • Chassis...'
    'hw_system_product'         = '  • System Product...'
    'hw_bios'                   = '  • BIOS...'
    'hw_secure_boot'            = '  • Secure Boot...'
    'hw_tpm'                    = '  • TPM...'
    'hw_cpu'                    = '  • CPU...'
    'hw_ram'                    = '  • RAM...'
    'hw_video'                  = '  • Video cards...'
    'hw_storage'                = '  • Storage...'
    'hw_partitions'             = '  • Partitions...'
    'hw_network'                = '  • Network adapters...'
    'hw_public_ip'              = '  • Public IP...'
    'hw_audio'                  = '  • Audio devices...'
    'hw_monitors'               = '  • Monitors...'
    'hw_os'                     = '  • Operating System...'
    'hw_unavailable'            = '    ℹ️ {0} unavailable'
    'hw_collected'              = '✅ Hardware information collected successfully'
    'hw_error'                  = '❌ Error collecting hardware information: {0}'
    'hw_continuing'             = '⚠️ Continuing without hardware data...'

    # Hardware Submission
    'hw_submitting'             = '📤 Submitting hardware information to server...'
    'hw_submitted'              = '✅ Hardware information submitted successfully'
    'hw_submit_failed'          = '❌ Failed to submit hardware info: {0}'
    'hw_submit_error'           = '❌ Error submitting hardware info: {0}'
    'hw_no_data'                = '⚠️ No hardware data to submit'

    # Pre-check
    'precheck_activated'        = '✅ Windows is already activated — no action needed.'
    'precheck_error'            = '⚠️ Cannot check current activation status. Proceeding...'

    # Completion
    'complete_success'          = '🎉 Activation complete! You can now close this window.'
    'complete_sticker'          = '🏷️ Please confirm that the license sticker has been placed on the PC case.'
    'complete_press_enter'      = 'Press Enter to continue'
    'complete_failed_keys'      = '⚠️ Activation failed after trying {0} keys.'
    'complete_press_any'        = 'Press any key to close...'
    'complete_closing'          = 'Closing in 5 seconds...'
}
