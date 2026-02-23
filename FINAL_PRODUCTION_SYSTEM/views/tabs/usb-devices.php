            <!-- USB Devices Tab Content -->
            <div id="usb-devices" class="tab-content">
                <h2><?= __('usb.title') ?></h2>

                <div class="toolbar">
                    <button class="btn btn-primary" onclick="showRegisterUSBModal()"><?= __('usb.register_new') ?></button>

                    <select id="usb-filter-technician" onchange="loadUSBDevices()" style="margin-left: 10px; padding: 8px;">
                        <option value=""><?= __('usb.all_technicians') ?></option>
                    </select>

                    <select id="usb-filter-status" onchange="loadUSBDevices()" style="margin-left: 10px; padding: 8px;">
                        <option value=""><?= __('usb.all_statuses') ?></option>
                        <option value="active"><?= __('usb.active') ?></option>
                        <option value="disabled"><?= __('usb.disabled') ?></option>
                        <option value="lost"><?= __('usb.lost') ?></option>
                        <option value="stolen"><?= __('usb.stolen') ?></option>
                    </select>

                    <button class="btn" onclick="loadUSBDevices()" style="margin-left: 10px;"><?= __('usb.refresh') ?></button>
                </div>

                <div id="usb-devices-stats" style="margin: 20px 0;"></div>
                <div id="usb-devices-table"></div>
            </div>
