{{--
    تایید غیرفعال‌سازی کاربر.

    نام کاربر هرگز داخل رشتهٔ جاوااسکریپت در یک attribute نوشته نمی‌شود:
    آنجا پارسر HTML موجودیت‌های بلید (مثل &#039;) را به نویسهٔ اصلی برمی‌گرداند
    و رشتهٔ JS می‌شکند. نام فقط در data-confirm-user می‌نشیند — یک مقدار داده،
    نه کد — و اینجا از dataset خوانده می‌شود.
--}}
<script>
    (function () {
        document.querySelectorAll('form[data-confirm-user]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var name = form.dataset.confirmUser || '';

                if (!window.confirm('حساب «' + name + '» غیرفعال شود؟')) {
                    event.preventDefault();
                }
            });
        });
    })();
</script>
