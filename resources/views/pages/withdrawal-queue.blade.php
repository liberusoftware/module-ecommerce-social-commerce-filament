{{-- A table on a page rather than a resource: the rows come from a domain query
     that names the work, and the queue is the number, not the model. --}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
