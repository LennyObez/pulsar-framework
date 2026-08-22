@extends('account.layout')

@section('title', $active_section ?? '')

@section('content')
{!! $section_html !!}
@endsection
