{{--
    The matter's OneDrive folder as a file manager, in "Show a file on the
    live view" on Record the meeting: its folders opened folder by folder,
    and the one thing to do with a file — show it over the minutes on every
    live view (MatterOneDriveFiles, picking for these minutes).
--}}
<div>
    @livewire(\App\Livewire\MatterOneDriveFiles::class, ['matter' => $matter, 'pickForMinutes' => $minutesId], key('pick-live-file-'.$minutesId))
</div>
