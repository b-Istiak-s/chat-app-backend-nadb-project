@if (session('status'))
    <div class="flash flash-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="flash flash-error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif
