<div>
    <div class="steps">
        @foreach ([
            1 => __('Requirements'),
            2 => __('License'),
            3 => __('Database'),
            4 => __('Application'),
            5 => __('Modules'),
            6 => __('Integrations'),
            7 => __('Install'),
            8 => __('Admin'),
            9 => __('Done'),
        ] as $number => $label)
            <div class="dot @if($step === $number) active @elseif($step > $number) done @endif">
                {{ $number }}. {{ $label }}
            </div>
        @endforeach
    </div>

    {{-- Step 1: Server requirements --}}
    @if ($step === 1)
        <div class="card">
            <h2>{{ __('Server Requirements') }}</h2>
            <p class="hint">{{ __('These must all pass before the installer can continue.') }}</p>

            <ul class="check-list">
                @foreach ($this->requirementChecks as $check)
                    <li>
                        <span>{{ $check['label'] }} — <span class="hint">{{ $check['detail'] }}</span></span>
                        <span class="badge {{ $check['ok'] ? 'ok' : ($check['critical'] ? 'fail' : 'warn') }}">
                            {{ $check['ok'] ? __('OK') : ($check['critical'] ? __('Failing') : __('Warning')) }}
                        </span>
                    </li>
                @endforeach
            </ul>

            <h2>{{ __('Composer & Frontend Build') }}</h2>
            <p class="hint">{{ __('If a tool is available on this server, you can run it here. Otherwise, run the shown command yourself and refresh this page.') }}</p>

            <ul class="check-list">
                @foreach ($this->packageChecks as $check)
                    <li>
                        <span>{{ $check['label'] }}</span>
                        <span style="display:flex; gap:8px; align-items:center;">
                            <span class="badge {{ $check['ok'] ? 'ok' : 'warn' }}">
                                {{ $check['ok'] ? __('OK') : __('Missing') }}
                            </span>
                            @unless ($check['ok'])
                                @if ($check['available'])
                                    <button type="button" class="btn secondary" wire:click="runPackageCommand('{{ $check['key'] }}')" wire:loading.attr="disabled" wire:target="runPackageCommand('{{ $check['key'] }}')">
                                        {{ $check['key'] === 'composer' ? __('Run composer install') : __('Run npm install && npm run build') }}
                                    </button>
                                @else
                                    <span class="hint">
                                        {{ $check['key'] === 'composer' ? __('Not detected — run: composer install --no-dev --optimize-autoloader') : __('Not detected — run: npm install && npm run build') }}
                                    </span>
                                @endif
                            @endunless
                        </span>
                    </li>
                @endforeach
            </ul>

            @error('requirements')
                <div class="alert danger">{{ $message }}</div>
            @enderror

            <div class="actions">
                <button type="button" class="btn" wire:click="continueFromRequirements">
                    {{ __('Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 2: License --}}
    @if ($step === 2)
        <div class="card">
            <h2>{{ __('License') }}</h2>
            <p class="hint">{{ __('Enter the license key issued for this deployment.') }}</p>

            <div class="field">
                <label>{{ __('License Key') }}</label>
                <input type="text" wire:model="license_key" placeholder="MIE-XXXXX-XXXXX-XXXXX-XXXXX">
                @error('license_key') <div class="error">{{ $message }}</div> @enderror
            </div>

            @if ($licenseActivated === true)
                <div class="alert success">{{ $licenseMessage }}</div>
            @elseif ($licenseActivated === false)
                <div class="alert danger">{{ $licenseMessage }}</div>
            @endif

            <div class="actions">
                <button type="button" class="btn secondary" wire:click="activateLicense" wire:loading.attr="disabled">
                    {{ __('Activate') }}
                </button>
                <button type="button" class="btn" wire:click="continueFromLicense">
                    {{ __('Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 3: Database configuration --}}
    @if ($step === 3)
        <div class="card">
            <h2>{{ __('Database Configuration') }}</h2>

            <div class="field">
                <label>{{ __('Connection') }}</label>
                <select wire:model.live="db_connection">
                    <option value="mysql">MySQL</option>
                    <option value="sqlite">SQLite</option>
                </select>
            </div>

            @if ($db_connection === 'mysql')
                <div class="row">
                    <div class="field">
                        <label>{{ __('Host') }}</label>
                        <input type="text" wire:model="db_host">
                        @error('db_host') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label>{{ __('Port') }}</label>
                        <input type="text" wire:model="db_port">
                        @error('db_port') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="field">
                    <label>{{ __('Database Name') }}</label>
                    <input type="text" wire:model="db_database">
                    @error('db_database') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="row">
                    <div class="field">
                        <label>{{ __('Username') }}</label>
                        <input type="text" wire:model="db_username">
                    </div>
                    <div class="field">
                        <label>{{ __('Password') }}</label>
                        <input type="password" wire:model="db_password">
                    </div>
                </div>
            @else
                <div class="field">
                    <label>{{ __('Database File Path') }}</label>
                    <input type="text" wire:model="db_database" placeholder="{{ database_path('database.sqlite') }}">
                    @error('db_database') <div class="error">{{ $message }}</div> @enderror
                </div>
            @endif

            @if ($connectionTested === true)
                <div class="alert success">{{ $connectionMessage }}</div>
            @elseif ($connectionTested === false)
                <div class="alert danger">{{ $connectionMessage }}</div>
            @endif

            <div class="actions">
                <button type="button" class="btn secondary" wire:click="testConnection" wire:loading.attr="disabled">
                    {{ __('Test Connection') }}
                </button>
                <button type="button" class="btn" wire:click="saveDatabaseAndContinue">
                    {{ __('Save & Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 4: Application details --}}
    @if ($step === 4)
        <div class="card">
            <h2>{{ __('Application Details') }}</h2>

            <div class="field">
                <label>{{ __('Application Name') }}</label>
                <input type="text" wire:model="app_name">
                @error('app_name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('Application URL') }}</label>
                <input type="text" wire:model="app_url" placeholder="https://example.com">
                <p class="hint">{{ __('Mail and other settings can be configured after installation, from Settings in the admin panel.') }}</p>
                @error('app_url') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('Company Name') }}</label>
                <input type="text" wire:model="company_name">
                <p class="hint">{{ __('Printed on vouchers, forms and other documents.') }}</p>
                @error('company_name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('Default Language') }}</label>
                <select wire:model="app_locale">
                    <option value="ar">العربية (Arabic)</option>
                    <option value="en">English</option>
                </select>
                <p class="hint">{{ __('The language users see until they pick their own. Can be changed later from Settings.') }}</p>
                @error('app_locale') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('Company Logo') }}</label>
                <input type="file" wire:model="company_logo" accept="image/*">
                <div wire:loading wire:target="company_logo" class="hint">{{ __('Uploading…') }}</div>
                @if ($company_logo && ! $errors->has('company_logo'))
                    <img src="{{ $company_logo->temporaryUrl() }}" alt="" style="max-height: 64px; margin-top: 8px;">
                @endif
                <p class="hint">{{ __('Optional. Shown in the panel header and in emails.') }}</p>
                @error('company_logo') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('Default User Avatar') }}</label>
                <input type="file" wire:model="default_avatar" accept="image/*">
                <div wire:loading wire:target="default_avatar" class="hint">{{ __('Uploading…') }}</div>
                @if ($default_avatar && ! $errors->has('default_avatar'))
                    <img src="{{ $default_avatar->temporaryUrl() }}" alt="" style="width: 64px; height: 64px; border-radius: 50%; object-fit: cover; margin-top: 8px;">
                @endif
                <p class="hint">{{ __('Optional. Used for users who haven\'t uploaded their own photo.') }}</p>
                @error('default_avatar') <div class="error">{{ $message }}</div> @enderror
            </div>

            <p class="hint">{{ __('Both can be changed later from Settings → Branding.') }}</p>

            <div class="actions">
                <button type="button" class="btn" wire:click="saveAppSettingsAndContinue" wire:loading.attr="disabled" wire:target="company_logo,default_avatar">
                    {{ __('Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 5: Modules --}}
    @if ($step === 5)
        <div class="card">
            <h2>{{ __('Modules') }}</h2>
            <p class="hint">{{ __('Turn off what this deployment doesn\'t need. Every table is still created either way — this only controls which panels and menus are enabled, and can be changed later.') }}</p>

            <div class="field">
                <label>
                    <input type="checkbox" wire:model="module_pms">
                    {{ __('Properties Management (PMS)') }}
                </label>
            </div>

            <div class="field">
                <label>
                    <input type="checkbox" checked disabled>
                    {{ __('Core (Parties) — always on, required by PMS') }}
                </label>
            </div>

            <div class="field">
                <label>
                    <input type="checkbox" wire:model.live="module_mms">
                    {{ __('Legal Management (MMS)') }}
                </label>
            </div>

            @if ($module_mms)
                <div style="margin-inline-start: 24px;">
                    <div class="field">
                        <label>
                            <input type="checkbox" wire:model="module_mms_payroll">
                            {{ __('Payroll & Incentives') }}
                        </label>
                    </div>
                    <div class="field">
                        <label>
                            <input type="checkbox" wire:model="module_mms_communications">
                            {{ __('Communications (Bulk Mail, Letter Templates)') }}
                        </label>
                    </div>
                    <div class="field">
                        <label>
                            <input type="checkbox" wire:model="module_mms_calendar">
                            {{ __('Calendar & Leave Requests') }}
                        </label>
                    </div>
                </div>
            @endif

            <div class="actions">
                <button type="button" class="btn" wire:click="saveModulesAndContinue">
                    {{ __('Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 6: Optional integrations (WhatsApp, Microsoft Graph mail) --}}
    @if ($step === 6)
        <div class="card">
            <h2>{{ __('Integrations') }}</h2>
            <p class="hint">{{ __('Optional — leave any of these blank to configure later from Settings. Only filled-in fields are saved.') }}</p>

            <h2>{{ __('WhatsApp') }}</h2>
            <div class="field">
                <label>{{ __('Phone Number ID') }}</label>
                <input type="text" wire:model="whatsapp_phone_id">
            </div>
            <div class="field">
                <label>{{ __('Access Token') }}</label>
                <input type="password" wire:model="whatsapp_token">
            </div>
            <div class="field">
                <label>{{ __('From Number') }}</label>
                <input type="text" wire:model="whatsapp_from">
            </div>

            <h2>{{ __('Microsoft Graph (Mail)') }}</h2>
            <p class="hint">{{ __('Used to send outbound mail through a Microsoft 365 mailbox instead of SMTP.') }}</p>
            <div class="field">
                <label>{{ __('Tenant ID') }}</label>
                <input type="text" wire:model="graph_tenant_id">
            </div>
            <div class="field">
                <label>{{ __('Client ID') }}</label>
                <input type="text" wire:model="graph_client_id">
            </div>
            <div class="field">
                <label>{{ __('Client Secret') }}</label>
                <input type="password" wire:model="graph_client_secret">
            </div>

            <div class="actions">
                <button type="button" class="btn" wire:click="saveIntegrationsAndContinue">
                    {{ __('Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 7: Install (migrate & seed), with a live progress bar --}}
    @if ($step === 7)
        <div
            class="card"
            x-data="{
                running: false,
                start() {
                    if (this.running) return;
                    this.running = true;
                    this.tick();
                },
                tick() {
                    if ($wire.migrated || $wire.migrationFailed) {
                        this.running = false;
                        return;
                    }
                    $wire.runNextInstallTask().then(() => this.tick());
                },
            }"
            x-init="start()"
        >
            <h2>{{ __('Install the Database') }}</h2>
            <p class="hint">{{ __('This creates every table the application needs and seeds permissions and default roles. It can take a moment.') }}</p>

            <div class="progress-bar">
                <div class="progress-bar-fill" style="width: {{ $this->installProgress }}%;"></div>
            </div>
            <p class="hint">{{ $this->installProgress }}%</p>

            <ul class="check-list">
                @foreach ($this->installTasks() as $key => $label)
                    <li>
                        <span>{{ $label }}</span>
                        <span class="badge {{ in_array($key, $completedInstallTasks, true) ? 'ok' : 'warn' }}">
                            {{ in_array($key, $completedInstallTasks, true) ? __('Done') : __('Pending') }}
                        </span>
                    </li>
                @endforeach
            </ul>

            @if ($migrationOutput !== '')
                <pre class="output">{{ $migrationOutput }}</pre>
            @endif

            @if ($migrationFailed)
                <div class="alert danger">{{ __('Installation failed. Fix the issue above and try again.') }}</div>
                <div class="actions">
                    <button type="button" class="btn secondary" x-on:click="$wire.set('migrationFailed', false).then(() => start())">
                        {{ __('Retry') }}
                    </button>
                </div>
            @endif

            <div class="actions">
                <button type="button" class="btn" wire:click="continueFromMigration" @disabled(! $migrated)>
                    {{ __('Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 8: Admin account --}}
    @if ($step === 8)
        <div class="card">
            <h2>{{ __('Create the Administrator Account') }}</h2>

            <div class="field">
                <label>{{ __('Name') }}</label>
                <input type="text" wire:model="admin_name">
                @error('admin_name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label>{{ __('Email') }}</label>
                <input type="email" wire:model="admin_email">
                @error('admin_email') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="row">
                <div class="field">
                    <label>{{ __('Password') }}</label>
                    <input type="password" wire:model="admin_password">
                    @error('admin_password') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label>{{ __('Confirm Password') }}</label>
                    <input type="password" wire:model="admin_password_confirmation">
                </div>
            </div>

            <div class="actions">
                <button type="button" class="btn" wire:click="createAdmin">
                    {{ __('Create Account & Continue') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Step 9: Complete --}}
    @if ($step === 9)
        <div class="card">
            <h2>{{ __('Installation Complete') }}</h2>
            <p>{{ __('The application is ready. This setup wizard will no longer be reachable once you continue.') }}</p>

            <div class="actions">
                {{-- A plain link, not a Livewire call: installation already
                     completed in the previous step, and this page's session
                     is no longer the one the app uses (see
                     InstallWizard::completeInstallation()). --}}
                <a href="{{ url('/') }}" class="btn">
                    {{ __('Go to Login') }}
                </a>
            </div>
        </div>
    @endif
</div>
