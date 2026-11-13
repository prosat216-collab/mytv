// القرآن الكريم: القرّاء، أسماء السور، وآيات الرقية الشرعية.
// الصوت يجي ديراكت من mp3quran.net (سورة كاملة) و everyayah.com (آية بآية للرقية)، والبراق من archive.org.
window.MYTV_QURAN = {
  reciters: [
    { id: 'basit', ar: 'عبد الباسط عبد الصمد', fr: 'Abdelbasset Abdessamad', rw: 'hafs',
      surah: 'https://cdn.mp3quran.net/audio/abdulbasit-abdulsamad/r3/', ayah: 'https://everyayah.com/data/Abdul_Basit_Murattal_192kbps/' },
    { id: 'minshawi', ar: 'محمد صديق المنشاوي', fr: 'Mohamed Siddiq El-Minshawi', rw: 'hafs',
      surah: 'https://cdn.mp3quran.net/audio/muhammad-minshawi/r1/', ayah: 'https://everyayah.com/data/Minshawy_Murattal_128kbps/' },
    { id: 'husary', ar: 'محمود خليل الحصري', fr: 'Mahmoud Khalil El-Husary', rw: 'hafs',
      surah: 'https://cdn.mp3quran.net/audio/mahmoud-husary/r1/', ayah: 'https://everyayah.com/data/Husary_128kbps/' },
    { id: 'ghamdi', ar: 'سعد الغامدي', fr: 'Saad El-Ghamdi', rw: 'hafs',
      surah: 'https://cdn.mp3quran.net/audio/saad-ghamdi/r1/', ayah: 'https://everyayah.com/data/Ghamadi_40kbps/' },
    { id: 'maher', ar: 'ماهر المعيقلي', fr: 'Maher El-Muaiqly', rw: 'hafs',
      surah: 'https://cdn.mp3quran.net/audio/maher-muaiqly/r1/', ayah: 'https://everyayah.com/data/MaherAlMuaiqly128kbps/' },
    { id: 'sudais', ar: 'عبد الرحمن السديس', fr: 'Abderrahman Es-Sudais', rw: 'hafs',
      surah: 'https://cdn.mp3quran.net/audio/abdulrahman-sudais/r1/', ayah: 'https://everyayah.com/data/Abdurrahmaan_As-Sudais_192kbps/' },
    { id: 'barrak', ar: 'علي البراق', fr: 'Ali El-Barrak', rw: 'qalun',
      surah: 'https://archive.org/download/128-kb---aly---albarraq-by--qaloon----mp3--full--mushaf--quran--114--sora--fro/', ayah: '' }
  ],
  // الرقية: [سورة، من آية، حتى آية]
  ruqyah: [
    [1, 1, 7], [2, 1, 5], [2, 102, 102], [2, 163, 164], [2, 255, 257], [2, 284, 286],
    [3, 18, 19], [7, 54, 56], [7, 117, 122], [10, 81, 82], [20, 69, 69], [23, 115, 118],
    [37, 1, 10], [46, 29, 32], [55, 33, 36], [59, 21, 24], [72, 1, 9],
    [112, 1, 4], [113, 1, 5], [114, 1, 6]
  ],
  // قارئ بلاش تلاوة آية بآية: الرقية بالسور القصار كاملة
  ruqyahShort: [1, 112, 113, 114],
  ar: ['الفاتحة', 'البقرة', 'آل عمران', 'النساء', 'المائدة', 'الأنعام', 'الأعراف', 'الأنفال', 'التوبة', 'يونس',
    'هود', 'يوسف', 'الرعد', 'إبراهيم', 'الحجر', 'النحل', 'الإسراء', 'الكهف', 'مريم', 'طه',
    'الأنبياء', 'الحج', 'المؤمنون', 'النور', 'الفرقان', 'الشعراء', 'النمل', 'القصص', 'العنكبوت', 'الروم',
    'لقمان', 'السجدة', 'الأحزاب', 'سبأ', 'فاطر', 'يس', 'الصافات', 'ص', 'الزمر', 'غافر',
    'فصلت', 'الشورى', 'الزخرف', 'الدخان', 'الجاثية', 'الأحقاف', 'محمد', 'الفتح', 'الحجرات', 'ق',
    'الذاريات', 'الطور', 'النجم', 'القمر', 'الرحمن', 'الواقعة', 'الحديد', 'المجادلة', 'الحشر', 'الممتحنة',
    'الصف', 'الجمعة', 'المنافقون', 'التغابن', 'الطلاق', 'التحريم', 'الملك', 'القلم', 'الحاقة', 'المعارج',
    'نوح', 'الجن', 'المزمل', 'المدثر', 'القيامة', 'الإنسان', 'المرسلات', 'النبأ', 'النازعات', 'عبس',
    'التكوير', 'الانفطار', 'المطففين', 'الانشقاق', 'البروج', 'الطارق', 'الأعلى', 'الغاشية', 'الفجر', 'البلد',
    'الشمس', 'الليل', 'الضحى', 'الشرح', 'التين', 'العلق', 'القدر', 'البينة', 'الزلزلة', 'العاديات',
    'القارعة', 'التكاثر', 'العصر', 'الهمزة', 'الفيل', 'قريش', 'الماعون', 'الكوثر', 'الكافرون', 'النصر',
    'المسد', 'الإخلاص', 'الفلق', 'الناس'],
  fr: ['Al-Fatiha', 'Al-Baqara', 'Al-Imran', 'An-Nisa', 'Al-Maida', 'Al-Anam', 'Al-Araf', 'Al-Anfal', 'At-Tawba', 'Yunus',
    'Hud', 'Yusuf', 'Ar-Rad', 'Ibrahim', 'Al-Hijr', 'An-Nahl', 'Al-Isra', 'Al-Kahf', 'Maryam', 'Ta-Ha',
    'Al-Anbiya', 'Al-Hajj', 'Al-Muminun', 'An-Nur', 'Al-Furqan', 'Ash-Shuara', 'An-Naml', 'Al-Qasas', 'Al-Ankabut', 'Ar-Rum',
    'Luqman', 'As-Sajda', 'Al-Ahzab', 'Saba', 'Fatir', 'Ya-Sin', 'As-Saffat', 'Sad', 'Az-Zumar', 'Ghafir',
    'Fussilat', 'Ash-Shura', 'Az-Zukhruf', 'Ad-Dukhan', 'Al-Jathiya', 'Al-Ahqaf', 'Muhammad', 'Al-Fath', 'Al-Hujurat', 'Qaf',
    'Adh-Dhariyat', 'At-Tur', 'An-Najm', 'Al-Qamar', 'Ar-Rahman', 'Al-Waqia', 'Al-Hadid', 'Al-Mujadila', 'Al-Hashr', 'Al-Mumtahana',
    'As-Saff', 'Al-Jumua', 'Al-Munafiqun', 'At-Taghabun', 'At-Talaq', 'At-Tahrim', 'Al-Mulk', 'Al-Qalam', 'Al-Haqqa', 'Al-Maarij',
    'Nuh', 'Al-Jinn', 'Al-Muzzammil', 'Al-Muddathir', 'Al-Qiyama', 'Al-Insan', 'Al-Mursalat', 'An-Naba', 'An-Naziat', 'Abasa',
    'At-Takwir', 'Al-Infitar', 'Al-Mutaffifin', 'Al-Inshiqaq', 'Al-Buruj', 'At-Tariq', 'Al-Ala', 'Al-Ghashiya', 'Al-Fajr', 'Al-Balad',
    'Ash-Shams', 'Al-Layl', 'Ad-Duha', 'Ash-Sharh', 'At-Tin', 'Al-Alaq', 'Al-Qadr', 'Al-Bayyina', 'Az-Zalzala', 'Al-Adiyat',
    'Al-Qaria', 'At-Takathur', 'Al-Asr', 'Al-Humaza', 'Al-Fil', 'Quraysh', 'Al-Maun', 'Al-Kawthar', 'Al-Kafirun', 'An-Nasr',
    'Al-Masad', 'Al-Ikhlas', 'Al-Falaq', 'An-Nas']
};
