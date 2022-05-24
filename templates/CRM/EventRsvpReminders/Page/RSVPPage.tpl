<h3>{ts}Thank you for your reply{/ts}</h3>

{if $error}
<p>{ts}There was an error processing your request.{/ts}</p>
<p>{ts}Unfortunetely, your answer has not been stored.{/ts}</p>
{if $admin_email }
    <p>{ts 1=$admin_email}Please contact <a href="mailto:%1">%1</a> to report this issue{/ts}</p>
{/if}
</p>
{else}

<p>{ts}Your answer has been stored.{/ts}</p>

{/if}

