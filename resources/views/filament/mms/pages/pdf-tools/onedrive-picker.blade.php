{{--
    "From OneDrive" for the PDF tools (resources/js/pdf/onedrive-picker.js): the user's own OneDrive browsed and
    searched for folders and PDFs. Where the tool takes several (pickFolders: Merge PDF), the open folder's PDFs can all be taken at once (Merge PDF), and a
    PDF taken already is marked.
--}}

        <div x-show="pickerOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" x-on:click.self="pickerOpen = false" x-on:keydown.escape.window="pickerOpen = false">
            <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl dark:bg-gray-900" x-trap.noscroll="pickerOpen">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 p-4 dark:border-white/10">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('From OneDrive') }}</h3>
                    <x-filament::icon-button icon="heroicon-o-x-mark" color="gray" :label="__('Close')" x-on:click="pickerOpen = false" />
                </div>

                <div class="flex flex-col gap-3 p-4">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input type="search" :placeholder="__('Search your OneDrive')" x-model="search" x-on:input.debounce.500ms="find()" />
                    </x-filament::input.wrapper>

                    <nav class="flex flex-wrap items-center gap-1 text-sm" x-show="search.trim().length < 2">
                        <button type="button" class="font-medium text-primary-600 hover:underline dark:text-primary-400" x-on:click="goTo(-1)">{{ __('My OneDrive') }}</button>
                        <template x-for="(crumb, index) in trail" :key="crumb.id">
                            <span class="flex items-center gap-1">
                                <span class="text-gray-400">/</span>
                                <button type="button" class="hover:underline" dir="auto"
                                    x-bind:class="index === trail.length - 1 ? 'text-gray-950 dark:text-white' : 'font-medium text-primary-600 dark:text-primary-400'"
                                    x-text="crumb.name" x-on:click="goTo(index)"></button>
                            </span>
                        </template>
                    </nav>

                    {{-- Merge PDF: every PDF of the folder open here, in the order of their names. --}}
                    <div x-show="pickFolders && search.trim().length < 2 && ! pickerLoading && folderPdfs.length" x-cloak class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-primary-50 p-2 text-sm dark:bg-primary-400/10">
                        <span class="text-primary-700 dark:text-primary-300" x-text="(labels.folderHas ?? '').replace(':count', folderPdfs.length)"></span>
                        <x-filament::button size="sm" icon="heroicon-o-document-duplicate" :loading-indicator="false" x-on:click="pickedOneDriveFolder()">
                            {{ __('Add every PDF in this folder') }}
                        </x-filament::button>
                    </div>
                </div>

                <div class="min-h-48 flex-1 overflow-y-auto px-4 pb-4">
                    <div x-show="pickerLoading" class="flex justify-center py-10">
                        <x-filament::loading-indicator class="size-6 text-primary-600" />
                    </div>

                    <p x-show="! pickerLoading && pickerError" class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400" x-text="pickerError"></p>

                    <p x-show="! pickerLoading && ! pickerError && ! items.length" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No folders or PDF files here.') }}
                    </p>

                    <ul x-show="! pickerLoading && ! pickerError" class="divide-y divide-gray-100 dark:divide-white/5">
                        <template x-for="item in items" :key="item.id">
                            <li>
                                <button type="button" class="flex w-full items-center gap-3 px-2 py-2 text-start text-sm hover:bg-gray-50 dark:hover:bg-white/5" x-on:click="choose(item)">
                                    <span x-show="item.folder"><x-filament::icon icon="heroicon-s-folder" class="size-5 text-warning-500" /></span>
                                    <span x-show="! item.folder"><x-filament::icon icon="heroicon-o-document" class="size-5 text-danger-600" /></span>
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span class="truncate text-gray-950 dark:text-white" dir="auto" x-text="item.name"></span>
                                        <span class="truncate text-xs text-gray-500 dark:text-gray-400" dir="auto" x-show="item.where" x-text="item.where"></span>
                                    </span>
                                    <span class="shrink-0 text-xs text-gray-500 tabular-nums dark:text-gray-400" x-show="item.size !== null" x-text="item.size !== null ? sizeOf(item.size) : ''"></span>
                                    <span x-show="pickFolders && ! item.folder && isAdded(item)" x-cloak :title="labels.added"><x-filament::icon icon="heroicon-s-check-circle" class="size-5 text-success-600" /></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
