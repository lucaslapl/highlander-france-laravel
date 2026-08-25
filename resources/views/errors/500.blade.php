@extends('layouts.main')

@section('title', '500 - ' . config('app.name'))

@section('content')
<div class="error-page" style="text-align: center; padding: 60px 20px;">
    <h2 style="font-size: 3rem; margin: 0;">500</h2>
    <p>Une erreur est survenue. Nos équipes ont été prévenues, réessayez plus tard.</p>
    <p><a href="/" style="color: #ff7b00;">Retour à l'accueil</a></p>
</div>
@endsection
