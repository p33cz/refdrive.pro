<?php get_header(); ?>

<div class="rdpro-page-wrap">
  <?php if (have_posts()): while (have_posts()): the_post(); ?>
    <article class="rdpro-page-content">
      <?php the_content(); ?>
    </article>
  <?php endwhile; endif; ?>
</div>

<?php get_footer(); ?>
