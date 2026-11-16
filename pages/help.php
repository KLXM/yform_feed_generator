<?php

$file = rex_path::addon('yform_feed_generator', 'README.md');
[$toc, $content] = rex_markdown::factory()->parseWithToc((string) rex_file::get($file), 2, 3, ['soft_line_breaks' => false, 'highlight_php' => true]);

$fragment = new rex_fragment();
$fragment->setVar('content', $content, false);
$fragment->setVar('toc', $toc, false);
echo $fragment->parse('core/page/docs.php');
