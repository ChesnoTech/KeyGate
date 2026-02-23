            <!-- Settings Tab Content -->
            <div id="settings" class="tab-content">
                <h2><?= __('settings.title') ?></h2>

                <div class="settings-section" style="background: white; padding: 20px; border-radius: 8px; margin-top: 20px;">
                    <h3 style="margin-top: 0;"><?= __('settings.alt_server_title') ?></h3>
                    <p style="color: #666; margin-bottom: 20px;"><?= __('settings.alt_server_desc') ?></p>

                    <form id="altServerForm" onsubmit="saveAltServerSettings(event); return false;">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="alt_server_enabled" name="alt_server_enabled" onchange="toggleAltServerConfig()">
                                <strong><?= __('settings.alt_server_enable') ?></strong>
                            </label>
                            <small style="display: block; color: #666; margin-left: 20px;"><?= __('settings.alt_server_desc2') ?></small>
                        </div>

                        <div id="alt_server_config_group" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                            <div class="form-group">
                                <label for="alt_server_script_path"><strong><?= __('settings.script_path') ?></strong></label>
                                <input type="text" id="alt_server_script_path" name="alt_server_script_path"
                                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                       placeholder="<?= __('settings.script_path_placeholder') ?>">
                                <small style="display: block; color: #666; margin-top: 5px;"><?= __('settings.script_path_desc') ?></small>
                            </div>

                            <div class="form-group">
                                <label for="alt_server_pre_command"><strong><?= __('settings.pre_command') ?></strong></label>
                                <input type="text" id="alt_server_pre_command" name="alt_server_pre_command"
                                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                       placeholder="<?= __('settings.pre_command_placeholder') ?>">
                                <small style="display: block; color: #666; margin-top: 5px;"><?= __('settings.pre_command_desc') ?></small>
                            </div>

                            <div class="form-group">
                                <label for="alt_server_script_args"><strong><?= __('settings.script_args') ?></strong></label>
                                <input type="text" id="alt_server_script_args" name="alt_server_script_args"
                                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                       placeholder="<?= __('settings.script_args_placeholder') ?>">
                                <small style="display: block; color: #666; margin-top: 5px;"><?= __('settings.script_args_desc') ?></small>
                            </div>

                            <div class="form-group">
                                <label for="alt_server_script_type"><strong><?= __('settings.script_type') ?></strong></label>
                                <select id="alt_server_script_type" name="alt_server_script_type"
                                        style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="cmd"><?= __('settings.script_type_cmd') ?></option>
                                    <option value="powershell"><?= __('settings.script_type_ps') ?></option>
                                    <option value="executable"><?= __('settings.script_type_exe') ?></option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="alt_server_timeout_seconds"><strong><?= __('settings.timeout') ?></strong></label>
                                <input type="number" id="alt_server_timeout_seconds" name="alt_server_timeout_seconds"
                                       style="width: 200px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                       value="300" min="30" max="600">
                                <small style="display: block; color: #666; margin-top: 5px;"><?= __('settings.timeout_desc') ?></small>
                            </div>

                            <h4 style="margin-top: 30px; margin-bottom: 15px;"><?= __('settings.behavior') ?></h4>

                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="alt_server_prompt_technician" name="alt_server_prompt_technician">
                                    <strong><?= __('settings.prompt_technician') ?></strong>
                                </label>
                                <small style="display: block; color: #666; margin-left: 20px;"><?= __('settings.prompt_desc') ?></small>
                            </div>

                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="alt_server_auto_failover" name="alt_server_auto_failover">
                                    <strong><?= __('settings.auto_failover') ?></strong>
                                </label>
                                <small style="display: block; color: #666; margin-left: 20px;"><?= __('settings.auto_failover_desc') ?></small>
                            </div>

                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="alt_server_verify_activation" name="alt_server_verify_activation">
                                    <strong><?= __('settings.verify_activation') ?></strong>
                                </label>
                                <small style="display: block; color: #666; margin-left: 20px;"><?= __('settings.verify_activation_desc') ?></small>
                            </div>
                        </div>

                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                            <button type="submit" class="btn btn-primary"><?= __('settings.save') ?></button>
                            <button type="button" class="btn" onclick="loadAltServerSettings()" style="margin-left: 10px;"><?= __('settings.reset') ?></button>
                        </div>
                    </form>
                </div>

                <!-- Client Resources: PS7 Installer Upload -->
                <div class="settings-section" style="background: white; padding: 20px; border-radius: 8px; margin-top: 20px;">
                    <h3 style="margin-top: 0;"><?= __('settings.client_resources') ?></h3>
                    <p style="color: #666; margin-bottom: 20px;"><?= __('settings.client_resources_desc') ?></p>

                    <h4 style="margin-bottom: 10px;"><?= __('settings.ps7_installer') ?></h4>
                    <div id="ps7InstallerCard"></div>

                    <div id="ps7UploadForm" style="display: none;">
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <input type="file" id="ps7FileInput" accept=".msi,.exe" style="flex: 1; min-width: 200px;">
                            <button type="button" class="btn btn-primary" onclick="uploadClientResource('ps7_installer')">
                                <?= __('settings.upload_installer') ?>
                            </button>
                        </div>
                        <div id="ps7UploadProgress" style="display: none; margin-top: 10px;">
                            <div style="background: #e9ecef; border-radius: 4px; overflow: hidden; height: 6px;">
                                <div id="ps7ProgressBar" style="background: var(--primary, #1a7f37); height: 100%; width: 0%; transition: width 0.3s;"></div>
                            </div>
                            <small id="ps7ProgressText" style="color: #666; margin-top: 4px; display: block;"></small>
                        </div>
                    </div>

                </div>
            </div>
