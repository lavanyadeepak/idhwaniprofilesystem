<?php
/**
 * Web UI for IDhwani Profile System
 */
require_once __DIR__ . '/resumeexporter.php';

// The logic in resumeexporter.php makes $basics, $works, etc. available here.
$name = $basics['name'] ?? 'Resume Owner';
$label = $basics['label'] ?? 'Professional';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($name); ?> - Resume Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">

    <nav class="bg-white border-b border-slate-200 py-4 px-6 mb-8 shadow-sm">
        <div class="max-w-6xl mx-auto flex justify-between items-center">
            <h1 class="text-xl font-bold text-slate-800 tracking-tight">IDhwani <span class="text-blue-600">Profile System</span></h1>
            <div class="text-xs font-bold text-slate-400 uppercase tracking-widest">Resume Manager</div>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-6 pb-12">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Sidebar: Profile Info -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                    <div class="w-16 h-16 bg-blue-600 text-white rounded-2xl flex items-center justify-center text-2xl font-bold mb-4 shadow-lg shadow-blue-200">
                        <?php echo substr($name, 0, 1); ?>
                    </div>
                    <h2 class="text-2xl font-bold text-slate-900 leading-tight"><?php echo e($name); ?></h2>
                    <p class="text-blue-600 font-semibold text-sm mb-4"><?php echo e($label); ?></p>
                    
                    <div class="space-y-3 text-sm border-t border-slate-100 pt-4 mt-4">
                        <div class="flex items-center text-slate-600">
                            <span class="mr-3 opacity-50">📧</span> <?php echo e($basics['email']); ?>
                        </div>
                        <div class="flex items-center text-slate-600">
                            <span class="mr-3 opacity-50">📱</span> <?php echo e($basics['phone']); ?>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Export Options</h3>
                    <div class="space-y-3">
                        <a href="resumeexporter.php?format=pdf" class="flex items-center justify-between p-4 rounded-xl bg-slate-50 hover:bg-red-50 hover:text-red-700 transition-all group border border-transparent hover:border-red-100">
                            <div class="flex items-center gap-3">
                                <span class="text-xl">📄</span>
                                <span class="font-bold text-sm text-slate-700 group-hover:text-red-700">Portable PDF</span>
                            </div>
                            <span class="text-xs opacity-0 group-hover:opacity-100 transition-opacity">Download →</span>
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                        <div class="text-2xl font-bold text-slate-800"><?php echo count($works); ?></div>
                        <div class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Experiences</div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                        <div class="text-2xl font-bold text-slate-800"><?php echo count($skills); ?></div>
                        <div class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Skill Sets</div>
                    </div>
                </div>
            </div>

            <!-- Content Area: Live Preview -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 
				-hidden flex flex-col h-[800px]">
                    <div class="bg-slate-50 border-b border-slate-200 px-6 py-4 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-slate-700 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-green-500"></span> Live Preview
                        </h3>
                        <div class="flex gap-1">
                            <div class="w-2 h-2 rounded-full bg-slate-200"></div>
                            <div class="w-2 h-2 rounded-full bg-slate-200"></div>
                            <div class="w-2 h-2 rounded-full bg-slate-200"></div>
                        </div>
                    </div>
                    <div class="flex-grow p-4 bg-slate-100 overflow-hidden">
                        <div class="max-w-[800px] mx-auto bg-white shadow-2xl rounded-sm min-h-full">
                            <iframe 
								srcdoc="<?php echo htmlspecialchars(generateFullHTML($basics, $works, $educations, $skills, $projects, $awards, $certificates, $profiles)); ?>"
								class="w-full h-full border-none"
								style="transform: scale(0.9); transform-origin: top center; min-height: 1200px;"
								scrolling="yes">
							</iframe>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Professional Summary</h3>
                    <p class="text-slate-600 text-sm italic leading-relaxed">
                        "<?php echo nl2br(e($basics['summary'])); ?>"
                    </p>
                </div>
            </div>
        </div>
    </main>

    <footer class="text-center py-12 text-slate-400 text-xs font-medium border-t border-slate-200 bg-white">
        <p><?php echo e($footerText); ?></p>
    </footer>
</body>
</html>