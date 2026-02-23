            <!-- Trusted Networks Tab (Super Admin Only) -->
            <div id="trusted-networks" class="tab-content">
                <h2><?= __('network.title') ?></h2>
                <p style="color: #666;"><?= __('network.desc') ?></p>

                <div style="margin: 20px 0;">
                    <button class="btn btn-primary" onclick="showAddTrustedNetworkModal()"><?= __('network.add') ?></button>
                </div>

                <div id="trusted-networks-loading" style="padding: 20px; text-align: center;">
                    <?= __('network.loading') ?>
                </div>
                <div id="trusted-networks-table-container"></div>

                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin-top: 20px;">
                    <h4><?= __('network.security_notice') ?></h4>
                    <ul style="margin: 10px 0;">
                        <li><?= __('network.usb_auth_only') ?></li>
                        <li><?= __('network.twofa_bypass') ?></li>
                        <li><?= __('network.cidr_format') ?></li>
                        <li><?= __('network.office_networks') ?></li>
                    </ul>
                </div>
            </div>
