<?php

// المفردات الثابتة القادمة من جهاز التشغيل أو قاعدة البيانات. أما النصوص التي
// يؤلفها جهاز التشغيل بحرية (أدلة النشاط، أسباب الدرجة، سطور السجل) فتبقى بلغتها
// الأصلية — راجع docs/LANGUAGES.md.

return [

    'run_status' => [
        'queued' => 'في الانتظار',
        'claimed' => 'قيد البدء',
        'running' => 'جارية',
        'completed' => 'مكتملة',
        'failed' => 'فاشلة',
        'cancelled' => 'ملغاة',
    ],

    'run_type' => [
        'scrape' => 'بحث',
        'reprocess' => 'إعادة معالجة',
        'import' => 'استيراد',
    ],

    'role' => [
        'admin' => 'مشرف',
        'member' => 'عضو',
    ],

    'priority' => [
        'Hot' => 'ساخن',
        'Warm' => 'دافئ',
        'Cold' => 'بارد',
    ],

    // مجالات التقييم، حسب المفتاح الذي يرسله جهاز التشغيل.
    'area' => [
        'web' => 'الموقع وتجربة المستخدم',
        'conversion' => 'التحويل وجمع العملاء',
        'paidMedia' => 'الإعلانات المدفوعة والتتبّع',
        'social' => 'التواجد على السوشيال والمحتوى',
        'automation' => 'المحادثة وسرعة الرد',
        'brand' => 'الهوية واتساق البيانات',
    ],

    // الباقات المقترحة، حسب النص الذي يرسله جهاز التشغيل حرفيًا.
    'pitch' => [
        'New website' => 'موقع إلكتروني جديد',
        'Website redesign / UI-UX' => 'إعادة تصميم الموقع / UI-UX',
        'Hosting, domain & email' => 'استضافة ونطاق وبريد إلكتروني',
        'Landing page & lead capture' => 'صفحة هبوط وجمع بيانات العملاء',
        'Online booking' => 'حجز إلكتروني',
        'E-commerce sales creatives' => 'تصاميم إعلانية للمتاجر الإلكترونية',
        'Meta awareness & lead-gen campaign' => 'حملة وعي وجذب عملاء على ميتا',
        'Google Discovery & SEM creatives' => 'إعلانات جوجل ديسكفري والبحث المدفوع',
        'Analytics setup' => 'إعداد أدوات التحليلات',
        'Reels & content production' => 'إنتاج ريلز ومحتوى',
        'AI chatbot kit' => 'باقة روبوت المحادثة بالذكاء الاصطناعي',
        'Community management' => 'إدارة مجتمع المتابعين',
        'Claim & fix Google listing' => 'توثيق وتحسين بيانات جوجل',
        'Branding & identity' => 'العلامة والهوية البصرية',
    ],

    // حالة الموقع، حسب النص الذي يرسله جهاز التشغيل حرفيًا. الحالتان اللتان تحملان
    // اسم منصة بين قوسين تُطابَقان ببدايتهما.
    'website_status' => [
        'No website' => 'لا يوجد موقع',
        'Has website' => 'لديه موقع',
        'Website down' => 'الموقع متوقف',
        'Website parked/expired' => 'الموقع متوقف/منتهي الصلاحية',
        'Website looks like a clone/spam site' => 'الموقع يبدو منسوخًا أو مزيفًا',
        'Has website (not checked: robots.txt)' => 'لديه موقع (لم يُفحص: robots.txt)',
        'Has website (blocks automated checks)' => 'لديه موقع (يمنع الفحص الآلي)',
        'Social page only' => 'صفحة تواصل اجتماعي فقط (:platform)',
        'Third-party page only' => 'صفحة على منصة خارجية فقط (:platform)',
    ],

    // سبب وضع النشاط في فئة تواصل معينة.
    'reason_code' => [
        'EMAIL_FOUND' => 'وُجد بريد إلكتروني',
        'WHATSAPP' => 'واتساب',
        'CONTACT_FORM' => 'نموذج تواصل',
        'INSTAGRAM_DM' => 'رسالة إنستجرام',
        'PHONE_CALL' => 'مكالمة أو رسالة نصية',
        'FACEBOOK_MESSENGER_ONLY' => 'ماسنجر فيسبوك فقط',
        'WEBSITE_NOT_CHECKABLE' => 'تعذّر فحص الموقع',
        'PHONE_ONLY' => 'هاتف فقط',
        'ADDRESS_ONLY' => 'عنوان فقط',
        'NO_CONTACT_DETAILS' => 'لا توجد بيانات تواصل',
    ],
];
