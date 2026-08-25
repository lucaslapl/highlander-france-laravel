@extends('layouts.main')

@section('title', '403 - ' . config('app.name'))

@section('content')
<div class="error-page" style="text-align: center; padding: 60px 20px;">
    <h2 style="font-size: 3rem; margin: 0;">403</h2>
    <p>Accès refusé.</p>
    <p><a href="/" style="color: #ff7b00;">Retour à l'accueil</a></p>
</div>
@endsection
