            <!-- Roles & Permissions Tab -->
            <div id="roles" class="tab-content">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                    <div>
                        <h2 style="margin:0;"><?= __('roles.title') ?></h2>
                        <p style="color:#666;margin:5px 0 0;"><?= __('roles.desc') ?></p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="showCreateRoleModal()"><?= __('roles.create') ?></button>
                        <button class="btn btn-secondary" onclick="loadACLChangelog()" style="margin-left:8px;"><?= __('roles.changelog') ?></button>
                    </div>
                </div>

                <!-- Roles Table -->
                <div style="background:white;border-radius:8px;overflow:hidden;">
                    <table class="data-table" id="roles-table">
                        <thead>
                            <tr>
                                <th><?= __('roles.header') ?></th>
                                <th><?= __('roles.type_col') ?></th>
                                <th><?= __('roles.permissions_col') ?></th>
                                <th><?= __('roles.users_col') ?></th>
                                <th><?= __('roles.status_col') ?></th>
                                <th><?= __('roles.actions_col') ?></th>
                            </tr>
                        </thead>
                        <tbody id="roles-table-body">
                            <tr><td colspan="6" style="text-align:center;padding:30px;"><?= __('roles.loading') ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Change Log Section (hidden by default) -->
                <div id="acl-changelog-section" style="display:none;margin-top:20px;">
                    <h3><?= __('roles.changelog_title') ?></h3>
                    <div id="acl-changelog-content" style="background:white;border-radius:8px;overflow:hidden;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?= __('roles.changelog_time') ?></th>
                                    <th><?= __('roles.changelog_actor') ?></th>
                                    <th><?= __('roles.changelog_action') ?></th>
                                    <th><?= __('roles.changelog_target') ?></th>
                                    <th><?= __('roles.changelog_details') ?></th>
                                </tr>
                            </thead>
                            <tbody id="acl-changelog-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
