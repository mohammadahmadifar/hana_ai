{{-- فرم مشترک ساخت و ویرایش کاربر --}}
@php
    $editing = isset($user) && $user !== null;
    $isSelf = $isSelf ?? false;
    $currentRole = old('role', $editing ? $user->role : \App\Models\User::DEFAULT_ROLE);
    $currentActive = (bool) old('is_active', $editing ? $user->is_active : true);
@endphp

<div class="formgrid">
    <div class="field">
        <label class="label" for="name">نام و نام خانوادگی <span class="label__req">*</span></label>
        <input class="input @error('name') is-invalid @enderror"
               id="name" type="text" name="name" maxlength="120"
               value="{{ old('name', $editing ? $user->name : '') }}" required>
        @error('name')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label class="label" for="email">ایمیل <span class="label__req">*</span></label>
        <input class="input input--ltr @error('email') is-invalid @enderror"
               id="email" type="email" name="email" maxlength="190" dir="ltr"
               autocomplete="off"
               value="{{ old('email', $editing ? $user->email : '') }}" required>
        <span class="hint">برای تماس و اطلاع‌رسانی؛ ورود به سامانه با کد ملی انجام می‌شود.</span>
        @error('email')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>
</div>

<div class="formgrid">
    {{--
        تسک ۷۴۰: نام کاربری ورود همین است، پس در فرم اجباری است — و روی حساب
        خودِ مدیر قفل است، دقیقاً مثل نقش و وضعیت حساب: اشتباه نوشتنش یعنی
        بیرون‌ماندن از سامانه‌ای که نه ثبت‌نام دارد نه بازیابی رمز.
    --}}
    <div class="field">
        <label class="label" for="national_id">
            کد ملی
            @unless ($isSelf)
                <span class="label__req">*</span>
            @endunless
        </label>
        @if ($isSelf)
            <input class="input input--ltr" id="national_id" type="text" dir="ltr"
                   value="{{ $user->national_id }}" readonly>
            <span class="hint">
                کد ملی حساب خودتان قابل تغییر نیست — همین کد نام کاربری ورود شماست و
                اشتباه نوشتنش شما را بیرون می‌گذارد. از مدیر دیگری بخواهید عوضش کند.
            </span>
        @else
            <input class="input input--ltr @error('national_id') is-invalid @enderror"
                   id="national_id" type="text" name="national_id" maxlength="20" dir="ltr"
                   inputmode="numeric" autocomplete="off"
                   value="{{ old('national_id', $editing ? $user->national_id : '') }}" required>
            <span class="hint">
                ده رقم با رقم کنترل معتبر. <strong>کاربر با همین کد وارد سامانه می‌شود</strong>،
                نه با ایمیل. ارقام فارسی هم پذیرفته می‌شود.
            </span>
        @endif
        @error('national_id')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>
</div>

<div class="formgrid">
    <div class="field">
        <label class="label" for="role">نقش کاربری <span class="label__req">*</span></label>
        @if ($isSelf)
            <input class="input" id="role" type="text" value="{{ $user->roleLabel() }}" readonly>
            <span class="hint">نقش حساب خودتان قابل تغییر نیست؛ از مدیر دیگری بخواهید آن را عوض کند.</span>
        @else
            <select class="select @error('role') is-invalid @enderror" id="role" name="role" required>
                @foreach ($roles as $key => $label)
                    <option value="{{ $key }}" @selected($currentRole === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="hint">
                مدیر سامانه به همه بخش‌ها، کارشناس بررسی به پرونده‌ها و صف بررسی،
                کارشناس داده به دیتاست و تگ‌گذاری، و متقاضی فقط به ثبت درخواست خودش
                و دیدن نتیجهٔ آن دسترسی دارد.
            </span>
        @endif
        @error('role')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <span class="label">وضعیت حساب</span>
        @if ($isSelf)
            <label class="check">
                <input type="checkbox" checked disabled>
                <span>فعال</span>
            </label>
            <span class="hint">نمی‌توانید حساب خودتان را غیرفعال کنید.</span>
        @else
            <input type="hidden" name="is_active" value="0">
            <label class="check">
                <input type="checkbox" name="is_active" value="1" @checked($currentActive)>
                <span>حساب فعال است و می‌تواند وارد سامانه شود</span>
            </label>
            <span class="hint">با برداشتن تیک، کاربر بلافاصله امکان ورود را از دست می‌دهد.</span>
        @endif
    </div>
</div>

<div class="formgrid">
    <div class="field">
        <label class="label" for="password">
            رمز عبور
            @if ($editing)
                <span class="muted small">(اختیاری)</span>
            @else
                <span class="label__req">*</span>
            @endif
        </label>
        <input class="input input--ltr @error('password') is-invalid @enderror"
               id="password" type="password" name="password" dir="ltr"
               autocomplete="new-password" {{ $editing ? '' : 'required' }}>
        <span class="hint">
            دست‌کم ۸ نویسه.
            @if ($editing)
                اگر خالی بماند رمز فعلی کاربر تغییر نمی‌کند.
            @endif
        </span>
        @error('password')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>

    <div class="field">
        <label class="label" for="password_confirmation">
            تکرار رمز عبور
            @unless ($editing)
                <span class="label__req">*</span>
            @endunless
        </label>
        <input class="input input--ltr"
               id="password_confirmation" type="password" name="password_confirmation" dir="ltr"
               autocomplete="new-password" {{ $editing ? '' : 'required' }}>
        <span class="hint">همان رمز را دوباره بنویسید.</span>
    </div>
</div>
