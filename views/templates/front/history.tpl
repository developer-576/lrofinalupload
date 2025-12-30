{extends file='page.tpl'}

{block name='page_content'}

<h1 class="page-heading">My Document History</h1>

{if $count_uploads == 0}
    <div class="alert alert-info text-center">
        You have not uploaded any documents yet.
    </div>
{else}

<table class="table table-bordered table-striped">
    <thead>
        <tr>
            <th>Date</th>
            <th>File</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>
    {foreach $uploads as $u}
        <tr>
            <td>{$u.date_uploaded|escape:'html':'UTF-8'}</td>
            <td>{$u.short_name|escape:'html':'UTF-8'}</td>

            <td>
                {if $u.status == 'approved'}
                    <span class="badge badge-success">Approved</span>
                {elseif $u.status == 'rejected'}
                    <span class="badge badge-danger">Rejected</span>
                {else}
                    <span class="badge badge-warning">Pending</span>
                {/if}
            </td>

            <td>
                <a class="btn btn-primary btn-sm"
                   href="{$link->getModuleLink('lrofileupload','history', ['download' => $u.id_upload])|escape:'html':'UTF-8'}">
                   Download
                </a>
            </td>
        </tr>
    {/foreach}
    </tbody>
</table>

{/if}

{/block}
