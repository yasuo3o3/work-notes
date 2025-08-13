(function(){
  function toggleCustom(select){
    var wrap = select.closest('p,div,fieldset') || select.parentNode;
    var input = wrap && wrap.querySelector('[data-ofwn-custom="'+select.name+'"]');
    if(!input) return;
    input.style.display = (select.value === '__custom__') ? '' : 'none';
  }
  document.addEventListener('change', function(e){
    if(e.target && e.target.matches('select[data-ofwn-select]')) toggleCustom(e.target);
  });
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('select[data-ofwn-select]').forEach(toggleCustom);
  });
})();