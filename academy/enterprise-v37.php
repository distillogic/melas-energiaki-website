<?php
declare(strict_types=1);
if(!defined('ACADEMY_ROOT')){http_response_code(404);exit;}

function academy_enterprise_lessons(): array
{
    static $lessons;
    if(is_array($lessons))return $lessons;
    $lessons=require __DIR__.'/enterprise-data-v37.php';
    foreach($lessons as $id=>&$lesson){
        $lesson['quiz']=[];
        foreach($lesson['question_rows'] as $i=>$row)$lesson['quiz'][]=academy_challenge_question($row,$id.':'.$i);
        unset($lesson['question_rows']);
    }unset($lesson);
    return $lessons;
}

function academy_render_theory_paragraph(mixed $paragraph,array &$seen): void
{
    if(is_string($paragraph)){echo '<p>'.sales_terms_html($paragraph,$seen).'</p>';return;}
    $heading=function_exists('academy_heading_display')?academy_heading_display($paragraph['heading']):$paragraph['heading'];
    echo '<div class="enterprise-topic"><h3>'.sales_terms_html($heading,$seen).'</h3>';
    foreach($paragraph['paragraphs'] as $text)echo '<p>'.sales_terms_html($text,$seen).'</p>';
    echo '</div>';
}
