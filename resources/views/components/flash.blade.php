{{-- Avisos de la última acción: lo que salió bien y lo que hay que corregir. --}}
@if (session('status'))
    <p role="status" data-feedback {{ $attributes->merge(['class' => 'border-l-4 border-brand bg-soft px-4 py-3 text-sm']) }}>{{ session('status') }}</p>
@endif

@if ($errors->any())
    <div role="alert" {{ $attributes->merge(['class' => 'border-l-4 border-danger bg-danger-soft px-4 py-3 text-sm text-danger']) }}>
        @foreach ($errors->all() as $message)
            <p>{{ $message }}</p>
        @endforeach
    </div>
@endif
