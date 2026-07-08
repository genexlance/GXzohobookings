(function(){'use strict';
  document.addEventListener('DOMContentLoaded',function(){
    var form=document.querySelector('.gx-zb-book-form');
    if(!form) return;
    var serviceSelect=document.getElementById('gx-zb-book-service');
    var staffSelect=document.getElementById('gx-zb-book-staff');
    var dateInput=document.getElementById('gx-zb-book-date');
    var slotsContainer=document.getElementById('gx-zb-book-slots');
    var hiddenSlot=document.getElementById('gx-zb-book-slot');
    var nameInput=document.getElementById('gx-zb-book-name');
    var emailInput=document.getElementById('gx-zb-book-email');
    var phoneInput=document.getElementById('gx-zb-book-phone');
    var submitBtn=document.getElementById('gx-zb-book-submit');
    var priceNote=document.getElementById('gx-zb-pay-note');
    var cfg=window.gxZbBook||{};
    var ajaxUrl=cfg.ajaxUrl||'';
    var nonce=cfg.nonce||'';
    function ajaxPost(action,data,cb){
      var xhr=new XMLHttpRequest();
      xhr.open('POST',ajaxUrl,true);
      xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
      xhr.onreadystatechange=function(){
        if(xhr.readyState===4){
          if(xhr.status>=200&&xhr.status<300){
            try{
              var res=JSON.parse(xhr.responseText);
              if(res.success&&res.data) cb(null,res.data);
              else cb(new Error('Invalid response'),null);
            }catch(e){
              cb(e,null);
            }
          }else{
            cb(new Error('HTTP error'),null);
          }
        }
      };
      var params='action='+encodeURIComponent(action)+'&_ajax_nonce='+encodeURIComponent(nonce);
      for(var key in data){
        if(data.hasOwnProperty(key)){
          params+='&'+encodeURIComponent(key)+'='+encodeURIComponent(data[key]);
        }
      }
      xhr.send(params);
    }
    function updateStaff(){
      var idx=serviceSelect.selectedIndex;
      var opt=serviceSelect.options[idx];
      var serviceId=opt?opt.value:'';
      var cost=opt?opt.getAttribute('data-cost'):'0';
      if(priceNote){
        var costNum=parseFloat(cost);
        if(serviceId){
          priceNote.style.display='block';
          if(costNum>0){
            priceNote.innerHTML='<span class="gx-zb-price">'+costNum.toFixed(2)+'</span> &mdash; Secure payment via Stripe';
          }else{
            priceNote.innerHTML='<span class="gx-zb-price">Free</span>';
          }
        }else{
          priceNote.style.display='none';
          priceNote.innerHTML='';
        }
      }
      if(!serviceId){ staffSelect.innerHTML='<option value="">Select service first</option>'; return; }
      staffSelect.disabled=true;
      staffSelect.innerHTML='<option value="">Loading...</option>';
      ajaxPost('gx_zb_staff',{service_id:serviceId},function(err,data){
        staffSelect.disabled=false;
        if(err){ staffSelect.innerHTML='<option value="">Error loading staff</option>'; return; }
        var html='<option value="">Select staff</option>';
        for(var i=0;i<data.length;i++){
          html+='<option value="'+data[i].id+'">'+data[i].name+'</option>';
        }
        staffSelect.innerHTML=html;
      });
    }
    function updateSlots(){
      var sidx=serviceSelect.selectedIndex;
      var serviceId=serviceSelect.options[sidx]?serviceSelect.options[sidx].value:'';
      var staffId=staffSelect.value;
      var dateVal=dateInput.value;
      if(!serviceId||!staffId||!dateVal){ slotsContainer.innerHTML=''; hiddenSlot.value=''; submitBtn.disabled=true; return; }
      slotsContainer.innerHTML='<p>Loading slots...</p>';
      ajaxPost('gx_zb_slots',{service_id:serviceId,staff_id:staffId,date:dateVal},function(err,data){
        if(err){
          slotsContainer.innerHTML='<p>Error loading slots</p>';
          return;
        }
        if(!data||data.length===0){
          slotsContainer.innerHTML='<p>No available slots</p>';
          hiddenSlot.value='';
          submitBtn.disabled=true;
          return;
        }
        var html='';
        for(var i=0;i<data.length;i++){
          html+='<button type="button" class="gx-zb-slot" data-slot="'+data[i]+'">'+data[i]+'</button>';
        }
        slotsContainer.innerHTML=html;
        var slotBtns=slotsContainer.querySelectorAll('.gx-zb-slot');
        for(var j=0;j<slotBtns.length;j++){
          slotBtns[j].addEventListener('click',slotClickHandler);
        }
        hiddenSlot.value='';
        submitBtn.disabled=true;
      });
    }
    function slotClickHandler(e){
      var btn=e.currentTarget;
      var slots=slotsContainer.querySelectorAll('.gx-zb-slot');
      for(var i=0;i<slots.length;i++){
        slots[i].classList.remove('is-selected');
      }
      btn.classList.add('is-selected');
      hiddenSlot.value=btn.getAttribute('data-slot');
      submitBtn.disabled=false;
    }
    if(serviceSelect){
      serviceSelect.addEventListener('change',function(){
        updateStaff();
        updateSlots();
      });
    }
    if(staffSelect){
      staffSelect.addEventListener('change',function(){
        updateSlots();
      });
    }
    if(dateInput){
      dateInput.addEventListener('change',function(){
        updateSlots();
      });
    }
    if(serviceSelect&&serviceSelect.options.length>1){
      updateStaff();
    }
  });
})();